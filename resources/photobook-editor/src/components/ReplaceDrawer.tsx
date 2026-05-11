import React, { useMemo, useState } from 'react';
import { ImageIcon } from 'lucide-react';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from './ui/sheet';
import { Button } from './ui/button';
import { Badge } from './ui/badge';
import { Label } from './ui/label';
import { Switch } from './ui/switch';
import { ScrollArea } from './ui/scroll-area';
import { cn } from '../lib/utils';

type Candidate = {
  path: string;
  filename: string;
  src?: string | null;
  width?: number | null;
  height?: number | null;
  ratio?: number | null;
  takenAt?: string | null;
  orientation?: 'landscape' | 'portrait' | 'square' | null;
};

type Props = {
  open: boolean;
  onClose: () => void;
  loading?: boolean;
  candidates: Candidate[];
  onPick: (candidate: Candidate, opts: { preserveCrop: boolean }) => void;
  onLoadAll?: () => void;
  showingAll?: boolean;
};

const nativeSelectClassName =
  'h-10 w-full rounded-xl border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900 shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-neutral-300';

const orientationLabels: Record<string, string> = {
  all: 'All',
  landscape: 'Landscape',
  portrait: 'Portrait',
  square: 'Square',
};

const recentLabels: Record<string, string> = {
  all: 'Any time',
  '7d': 'Last 7 days',
  '30d': 'Last 30 days',
};

function formatTakenAt(value?: string | null) {
  if (!value) return null;
  const timestamp = Date.parse(value);
  if (Number.isNaN(timestamp)) return null;
  return new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' }).format(timestamp);
}

export default function ReplaceDrawer({
  open,
  onClose,
  loading = false,
  candidates,
  onPick,
  onLoadAll,
  showingAll = false,
}: Props) {
  const [preserveCrop, setPreserveCrop] = useState(true);
  const [filterOrientation, setFilterOrientation] = useState('all');
  const [filterRecent, setFilterRecent] = useState('all');

  const filtered = useMemo(() => {
    const now = Date.now();
    const maxAgeMs =
      filterRecent === '7d' ? 7 * 24 * 3600 * 1000 : filterRecent === '30d' ? 30 * 24 * 3600 * 1000 : null;

    return candidates.filter((candidate) => {
      if (filterOrientation !== 'all' && candidate.orientation !== filterOrientation) return false;
      if (maxAgeMs && candidate.takenAt) {
        const timestamp = Date.parse(candidate.takenAt);
        if (!Number.isNaN(timestamp) && now - timestamp > maxAgeMs) return false;
      }
      return true;
    });
  }, [candidates, filterOrientation, filterRecent]);

  return (
    <Sheet
      open={open}
      onOpenChange={(nextOpen) => {
        if (!nextOpen) onClose();
      }}
    >
      <SheetContent side="right" className="w-[92vw] max-w-[30rem] p-0">
        <SheetHeader className="border-b border-neutral-200 px-6 py-5 pr-12">
          <div className="flex items-center gap-2">
            <SheetTitle>Replace Photo</SheetTitle>
            {showingAll && <Badge>All Images</Badge>}
          </div>
          <SheetDescription>
            Choose a new photo for the selected slot, then decide whether to keep the current crop framing.
          </SheetDescription>
        </SheetHeader>

        <div className="border-b border-neutral-200 bg-neutral-50/80 px-6 py-4">
          <div className="grid gap-4">
            <div className="grid gap-2 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="replace-orientation">Orientation</Label>
                <select
                  id="replace-orientation"
                  className={nativeSelectClassName}
                  value={filterOrientation}
                  onChange={(event) => setFilterOrientation(event.target.value)}
                >
                  {Object.entries(orientationLabels).map(([value, label]) => (
                    <option key={value} value={value}>
                      {label}
                    </option>
                  ))}
                </select>
              </div>

              <div className="space-y-2">
                <Label htmlFor="replace-recent">Taken</Label>
                <select
                  id="replace-recent"
                  className={nativeSelectClassName}
                  value={filterRecent}
                  onChange={(event) => setFilterRecent(event.target.value)}
                >
                  {Object.entries(recentLabels).map(([value, label]) => (
                    <option key={value} value={value}>
                      {label}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div className="flex items-start justify-between gap-4 rounded-2xl border border-neutral-200 bg-white p-4">
              <div className="space-y-1">
                <Label htmlFor="preserve-crop" className="text-neutral-900">
                  Preserve crop
                </Label>
                <p className="text-sm text-neutral-500">
                  Keep the current zoom and framing when you swap the image.
                </p>
              </div>
              <Switch id="preserve-crop" checked={preserveCrop} onCheckedChange={setPreserveCrop} />
            </div>
          </div>
        </div>

        <div className="flex items-center justify-between gap-3 border-b border-neutral-200 px-6 py-3">
          <div className="text-xs text-neutral-500">
            {loading ? 'Loading candidates…' : `${filtered.length} candidate${filtered.length === 1 ? '' : 's'}`}
          </div>
          {onLoadAll && !showingAll && (
            <Button size="sm" variant="outline" onClick={onLoadAll}>
              Show All Images
            </Button>
          )}
        </div>

        <ScrollArea className="flex-1">
          <div className="grid grid-cols-2 gap-3 p-6">
            {loading && (
              <div className="col-span-2 rounded-2xl border border-dashed border-neutral-200 bg-neutral-50 px-4 py-10 text-center text-sm text-neutral-500">
                Loading candidates…
              </div>
            )}

            {!loading &&
              filtered.map((candidate, index) => {
                const takenAt = formatTakenAt(candidate.takenAt);
                const orientation = candidate.orientation ? orientationLabels[candidate.orientation] : null;

                return (
                  <button
                    key={`${candidate.path}-${index}`}
                    type="button"
                    className={cn(
                      'group overflow-hidden rounded-[22px] border border-neutral-200 bg-white text-left shadow-sm transition-all hover:-translate-y-0.5 hover:border-neutral-300 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neutral-400'
                    )}
                    title={candidate.filename}
                    onClick={() => onPick(candidate, { preserveCrop })}
                  >
                    <div className="aspect-square overflow-hidden bg-neutral-100">
                      {candidate.src ? (
                        <img
                          src={candidate.src}
                          alt={candidate.filename}
                          className="h-full w-full object-cover transition-transform duration-200 group-hover:scale-[1.03]"
                        />
                      ) : (
                        <div className="flex h-full items-center justify-center text-neutral-400">
                          <ImageIcon className="h-6 w-6" />
                        </div>
                      )}
                    </div>
                    <div className="space-y-2 p-3">
                      <div className="line-clamp-2 text-sm font-medium text-neutral-900">{candidate.filename}</div>
                      <div className="flex flex-wrap gap-2">
                        {orientation && <Badge>{orientation}</Badge>}
                        {takenAt && <Badge>{takenAt}</Badge>}
                      </div>
                    </div>
                  </button>
                );
              })}

            {!loading && filtered.length === 0 && (
              <div className="col-span-2 rounded-2xl border border-dashed border-neutral-200 bg-neutral-50 px-4 py-10 text-center">
                <div className="text-sm font-medium text-neutral-800">No candidates found</div>
                <p className="mt-1 text-sm text-neutral-500">
                  Try relaxing the orientation or date filters to surface more images.
                </p>
              </div>
            )}
          </div>
        </ScrollArea>
      </SheetContent>
    </Sheet>
  );
}
