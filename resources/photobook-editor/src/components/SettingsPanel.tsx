import React, { useEffect, useState } from 'react';
import { PB, type AppSettings } from '../lib/api';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from './ui/dialog';
import { Button } from './ui/button';
import { Input } from './ui/input';
import { Label } from './ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from './ui/card';
import { Badge } from './ui/badge';
import { Separator } from './ui/separator';
import { Switch } from './ui/switch';
import { Checkbox } from './ui/checkbox';

interface SettingsPanelProps {
  open: boolean;
  onClose: () => void;
}

function MetricCard({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-2xl border border-neutral-200 bg-neutral-50/80 p-4">
      <div className="text-[11px] font-medium uppercase tracking-[0.16em] text-neutral-500">{label}</div>
      <div className="mt-2 text-sm font-semibold text-neutral-900">{value}</div>
    </div>
  );
}

function NumberField({
  id,
  label,
  suffix,
  value,
  onChange,
  disabled,
  min,
  max,
  step,
}: {
  id: string;
  label: string;
  suffix?: string;
  value: number;
  onChange: (next: number) => void;
  disabled?: boolean;
  min?: number;
  max?: number;
  step?: number;
}) {
  return (
    <div className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_120px_auto] sm:items-center">
      <Label htmlFor={id}>{label}</Label>
      <Input
        id={id}
        type="number"
        min={min}
        max={max}
        step={step}
        value={value}
        disabled={disabled}
        onChange={(event) => onChange(parseFloat(event.target.value) || 0)}
      />
      {suffix ? <span className="text-sm text-neutral-500">{suffix}</span> : <span />}
    </div>
  );
}

export default function SettingsPanel({ open, onClose }: SettingsPanelProps) {
  const [settings, setSettings] = useState<AppSettings | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [printEnabled, setPrintEnabled] = useState(false);
  const [bleedMm, setBleedMm] = useState(3.0);
  const [cropMarks, setCropMarks] = useState(true);
  const [spineMarginMm, setSpineMarginMm] = useState(10.0);

  useEffect(() => {
    if (!open) return;
    setLoading(true);
    setError(null);

    PB.getSettings()
      .then((response) => {
        setSettings(response.settings);
        setPrintEnabled(response.settings.print.enabled);
        setBleedMm(response.settings.print.bleed_mm);
        setCropMarks(response.settings.print.crop_marks);
        setSpineMarginMm(response.settings.print.spine_margin_mm);
      })
      .catch((err) => setError(err.message || 'Failed to load settings'))
      .finally(() => setLoading(false));
  }, [open]);

  const handleSave = async () => {
    setSaving(true);
    setError(null);
    try {
      await PB.updateSettings({
        print: {
          enabled: printEnabled,
          bleed_mm: bleedMm,
          crop_marks: cropMarks,
          spine_margin_mm: spineMarginMm,
          safe_zone_mm: settings?.print.safe_zone_mm ?? 5.0,
        },
      });
      onClose();
    } catch (err: any) {
      setError(err.message || 'Failed to save settings');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(nextOpen) => {
        if (!nextOpen) onClose();
      }}
    >
      <DialogContent className="max-w-3xl overflow-hidden p-0">
        <DialogHeader className="border-b border-neutral-200 px-6 py-5">
          <div className="flex items-start justify-between gap-4 pr-10">
            <div className="space-y-2">
              <DialogTitle>Editor Settings</DialogTitle>
              <DialogDescription>
                Manage print production defaults and confirm the current editor environment before exporting.
              </DialogDescription>
            </div>
            {settings && (
              <Badge variant={settings.nextcloud.configured ? 'success' : 'default'}>
                {settings.nextcloud.configured ? 'Nextcloud Connected' : 'Nextcloud Not Configured'}
              </Badge>
            )}
          </div>
        </DialogHeader>

        <div className="max-h-[82vh] overflow-y-auto px-6 py-5">
          {loading ? (
            <div className="rounded-2xl border border-dashed border-neutral-200 bg-neutral-50 px-6 py-10 text-center text-sm text-neutral-500">
              Loading settings…
            </div>
          ) : error ? (
            <div className="rounded-2xl border border-red-200 bg-red-50 px-6 py-10 text-center text-sm text-red-600">
              {error}
            </div>
          ) : (
            <div className="space-y-5">
              <Card>
                <CardHeader>
                  <CardTitle>Workspace</CardTitle>
                  <CardDescription>
                    These values come from the backend configuration and help explain how the current book is rendered.
                  </CardDescription>
                </CardHeader>
                <CardContent className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                  <MetricCard label="Paper" value={`${settings?.paper ?? '—'} ${settings?.orientation ?? ''}`.trim()} />
                  <MetricCard label="DPI" value={String(settings?.dpi ?? '—')} />
                  <MetricCard label="Page Frame" value={`${settings?.page_frame_mm ?? '—'} mm`} />
                  <MetricCard label="Page Gap" value={`${settings?.page_gap_mm ?? '—'} mm`} />
                </CardContent>
              </Card>

              <Card>
                <CardHeader>
                  <CardTitle>Print Production</CardTitle>
                  <CardDescription>
                    Turn on print mode when you need bleed, binding margin, and trim guidance for press-ready output.
                  </CardDescription>
                </CardHeader>
                <CardContent className="space-y-5">
                  <div className="flex items-start justify-between gap-4 rounded-2xl border border-neutral-200 bg-neutral-50/70 p-4">
                    <div className="space-y-1">
                      <Label htmlFor="print-mode" className="text-base text-neutral-900">
                        Print mode
                      </Label>
                      <p className="text-sm text-neutral-500">
                        Enable bleed, crop marks, and spine margins for exported layouts.
                      </p>
                    </div>
                    <Switch id="print-mode" checked={printEnabled} onCheckedChange={setPrintEnabled} />
                  </div>

                  <div className={!printEnabled ? 'pointer-events-none opacity-55' : ''}>
                    <div className="grid gap-4">
                      <NumberField
                        id="bleed-mm"
                        label="Bleed"
                        suffix="mm"
                        value={bleedMm}
                        onChange={setBleedMm}
                        disabled={!printEnabled}
                        min={0}
                        max={10}
                        step={0.5}
                      />
                      <NumberField
                        id="spine-margin-mm"
                        label="Spine margin"
                        suffix="mm for binding"
                        value={spineMarginMm}
                        onChange={setSpineMarginMm}
                        disabled={!printEnabled}
                        min={0}
                        max={25}
                        step={1}
                      />
                    </div>

                    <Separator className="my-5" />

                    <div className="flex items-start gap-3 rounded-2xl border border-neutral-200 p-4">
                      <Checkbox
                        id="crop-marks"
                        checked={cropMarks}
                        disabled={!printEnabled}
                        onCheckedChange={(checked) => setCropMarks(checked === true)}
                      />
                      <div className="space-y-1">
                        <Label htmlFor="crop-marks" className="text-neutral-900">
                          Crop marks
                        </Label>
                        <p className="text-sm text-neutral-500">
                          Show trim marks so the final PDF is easier to align in downstream print prep.
                        </p>
                      </div>
                    </div>
                  </div>
                </CardContent>
              </Card>

              <Card className={printEnabled ? 'border-amber-200 bg-amber-50/70' : ''}>
                <CardHeader>
                  <div className="flex items-center gap-2">
                    <CardTitle>Export Workflow</CardTitle>
                    {printEnabled && <Badge variant="warning">CMYK Follow-up Needed</Badge>}
                  </div>
                  <CardDescription>
                    PDFs are produced in RGB. Keep this conversion step handy for the print handoff.
                  </CardDescription>
                </CardHeader>
                <CardContent>
                  <div className="rounded-2xl bg-neutral-950 px-4 py-3 font-mono text-xs text-neutral-100">
                    ./scripts/convert-to-cmyk.sh storage/app/pdf-exports/photobook.pdf
                  </div>
                </CardContent>
              </Card>
            </div>
          )}
        </div>

        <DialogFooter className="border-t border-neutral-200 px-6 py-4">
          <Button variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button variant="brand" onClick={handleSave} disabled={loading || saving}>
            {saving ? 'Saving…' : 'Save Settings'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
