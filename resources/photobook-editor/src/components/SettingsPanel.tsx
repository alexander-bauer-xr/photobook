import React, { useState, useEffect } from 'react';
import { PB, type AppSettings, type PrintSettings } from '../lib/api';

interface SettingsPanelProps {
  open: boolean;
  onClose: () => void;
}

export default function SettingsPanel({ open, onClose }: SettingsPanelProps) {
  const [settings, setSettings] = useState<AppSettings | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Local editable state for print settings
  const [printEnabled, setPrintEnabled] = useState(false);
  const [bleedMm, setBleedMm] = useState(3.0);
  const [cropMarks, setCropMarks] = useState(true);
  const [spineMarginMm, setSpineMarginMm] = useState(10.0);

  useEffect(() => {
    if (!open) return;
    setLoading(true);
    setError(null);
    PB.getSettings()
      .then(res => {
        setSettings(res.settings);
        setPrintEnabled(res.settings.print.enabled);
        setBleedMm(res.settings.print.bleed_mm);
        setCropMarks(res.settings.print.crop_marks);
        setSpineMarginMm(res.settings.print.spine_margin_mm);
      })
      .catch(err => setError(err.message || 'Failed to load settings'))
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

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      <div className="absolute inset-0 bg-black/50" onClick={onClose} />
      <div className="relative bg-white rounded-lg shadow-xl w-full max-w-lg mx-4 max-h-[90vh] overflow-auto">
        <header className="px-6 py-4 border-b border-neutral-200 flex items-center justify-between">
          <h2 className="text-lg font-semibold text-neutral-800">Settings</h2>
          <button
            onClick={onClose}
            className="text-neutral-500 hover:text-neutral-800 text-xl leading-none"
          >
            ×
          </button>
        </header>

        <div className="px-6 py-4">
          {loading ? (
            <div className="text-center py-8 text-neutral-500">Loading settings…</div>
          ) : error ? (
            <div className="text-center py-8 text-red-600">{error}</div>
          ) : (
            <div className="space-y-6">
              {/* General Info */}
              <section>
                <h3 className="text-sm font-medium text-neutral-600 mb-3 uppercase tracking-wide">General</h3>
                <div className="grid grid-cols-2 gap-3 text-sm">
                  <div className="bg-neutral-50 rounded p-3">
                    <div className="text-neutral-500 text-xs mb-1">Paper</div>
                    <div className="font-medium capitalize">{settings?.paper} {settings?.orientation}</div>
                  </div>
                  <div className="bg-neutral-50 rounded p-3">
                    <div className="text-neutral-500 text-xs mb-1">DPI</div>
                    <div className="font-medium">{settings?.dpi}</div>
                  </div>
                  <div className="bg-neutral-50 rounded p-3">
                    <div className="text-neutral-500 text-xs mb-1">Page Frame</div>
                    <div className="font-medium">{settings?.page_frame_mm} mm</div>
                  </div>
                  <div className="bg-neutral-50 rounded p-3">
                    <div className="text-neutral-500 text-xs mb-1">Page Gap</div>
                    <div className="font-medium">{settings?.page_gap_mm} mm</div>
                  </div>
                </div>
              </section>

              {/* Print Settings */}
              <section>
                <h3 className="text-sm font-medium text-neutral-600 mb-3 uppercase tracking-wide">Print Production</h3>
                <div className="space-y-4">
                  {/* Print Mode Toggle */}
                  <label className="flex items-center gap-3 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={printEnabled}
                      onChange={(e) => setPrintEnabled(e.target.checked)}
                      className="w-5 h-5 rounded border-neutral-300 text-green-600 focus:ring-green-500"
                    />
                    <div>
                      <div className="font-medium text-neutral-800">Print Mode</div>
                      <div className="text-xs text-neutral-500">Enable bleed, crop marks, and spine margins</div>
                    </div>
                  </label>

                  {/* Print options (enabled when print mode is on) */}
                  <div className={`space-y-4 pl-8 ${!printEnabled ? 'opacity-50 pointer-events-none' : ''}`}>
                    {/* Bleed */}
                    <div className="flex items-center gap-3">
                      <label className="text-sm text-neutral-700 w-32">Bleed</label>
                      <input
                        type="number"
                        step="0.5"
                        min="0"
                        max="10"
                        value={bleedMm}
                        onChange={(e) => setBleedMm(parseFloat(e.target.value) || 0)}
                        className="w-20 px-2 py-1 border border-neutral-300 rounded text-sm"
                        disabled={!printEnabled}
                      />
                      <span className="text-sm text-neutral-500">mm</span>
                    </div>

                    {/* Spine Margin */}
                    <div className="flex items-center gap-3">
                      <label className="text-sm text-neutral-700 w-32">Spine Margin</label>
                      <input
                        type="number"
                        step="1"
                        min="0"
                        max="25"
                        value={spineMarginMm}
                        onChange={(e) => setSpineMarginMm(parseFloat(e.target.value) || 0)}
                        className="w-20 px-2 py-1 border border-neutral-300 rounded text-sm"
                        disabled={!printEnabled}
                      />
                      <span className="text-sm text-neutral-500">mm (for binding)</span>
                    </div>

                    {/* Crop Marks */}
                    <label className="flex items-center gap-3 cursor-pointer">
                      <input
                        type="checkbox"
                        checked={cropMarks}
                        onChange={(e) => setCropMarks(e.target.checked)}
                        className="w-5 h-5 rounded border-neutral-300 text-green-600 focus:ring-green-500"
                        disabled={!printEnabled}
                      />
                      <div>
                        <div className="text-sm text-neutral-800">Crop Marks</div>
                        <div className="text-xs text-neutral-500">Show trim marks for cutting</div>
                      </div>
                    </label>
                  </div>
                </div>
              </section>

              {/* Nextcloud Status */}
              <section>
                <h3 className="text-sm font-medium text-neutral-600 mb-3 uppercase tracking-wide">Integration</h3>
                <div className="flex items-center gap-2 text-sm">
                  <span className={`w-2 h-2 rounded-full ${settings?.nextcloud.configured ? 'bg-green-500' : 'bg-neutral-300'}`} />
                  <span className="text-neutral-700">
                    Nextcloud {settings?.nextcloud.configured ? 'connected' : 'not configured'}
                  </span>
                </div>
              </section>

              {/* Print Workflow Info */}
              {printEnabled && (
                <section className="bg-amber-50 border border-amber-200 rounded-lg p-4">
                  <h4 className="text-sm font-medium text-amber-800 mb-2">⚠️ Print Workflow</h4>
                  <p className="text-xs text-amber-700 leading-relaxed">
                    PDFs are generated in RGB. For professional printing, convert to CMYK using the provided script:
                  </p>
                  <code className="block mt-2 text-xs bg-amber-100 p-2 rounded font-mono text-amber-900">
                    ./scripts/convert-to-cmyk.sh storage/app/pdf-exports/photobook.pdf
                  </code>
                </section>
              )}
            </div>
          )}
        </div>

        <footer className="px-6 py-4 border-t border-neutral-200 flex justify-end gap-3">
          <button
            onClick={onClose}
            className="px-4 py-2 text-sm text-neutral-600 hover:text-neutral-800"
          >
            Cancel
          </button>
          <button
            onClick={handleSave}
            disabled={loading || saving}
            className="px-4 py-2 text-sm bg-neutral-800 text-white rounded hover:bg-neutral-700 disabled:opacity-50"
          >
            {saving ? 'Saving…' : 'Save Settings'}
          </button>
        </footer>
      </div>
    </div>
  );
}
