import React, { useMemo, useState } from 'react';

type Candidate = { path: string; filename: string; src?: string | null; width?: number | null; height?: number | null; ratio?: number | null; takenAt?: string | null; orientation?: 'landscape'|'portrait'|'square'|null };

type Props = {
  open: boolean;
  onClose: () => void;
  loading?: boolean;
  candidates: Candidate[];
  onPick: (c: Candidate, opts: { preserveCrop: boolean }) => void;
};

export default function ReplaceDrawer({ open, onClose, loading = false, candidates, onPick }: Props) {
  const [preserveCrop, setPreserveCrop] = useState(true);
  const [filterOrientation, setFilterOrientation] = useState('all');
  const [filterRecent, setFilterRecent] = useState('all');

  const filtered = useMemo(()=>{
    const now = Date.now();
    const maxAgeMs = filterRecent === '7d' ? 7*24*3600*1000 : filterRecent === '30d' ? 30*24*3600*1000 : null;
    return candidates.filter(c => {
      if (filterOrientation !== 'all' && c.orientation !== filterOrientation) return false;
      if (maxAgeMs && c.takenAt) {
        const t = Date.parse(c.takenAt);
        if (!isNaN(t)) {
          if ((now - t) > maxAgeMs) return false;
        }
      }
      return true;
    });
  }, [candidates, filterOrientation, filterRecent]);

  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50">
      <div className="absolute inset-0 bg-black/40" onClick={onClose} />
      <div className="absolute right-0 top-0 h-full w-[420px] bg-white shadow-xl border-l border-neutral-200 flex flex-col">
        <div className="p-4 border-b border-neutral-200 flex items-center justify-between">
          <div className="font-semibold">Replace Photo</div>
          <button className="px-2 py-1 text-sm rounded bg-neutral-200" onClick={onClose}>Close</button>
        </div>
        <div className="px-3 pt-3 pb-2 border-b border-neutral-200 flex items-center gap-2 text-xs">
          <label className="flex items-center gap-1">
            <span className="text-neutral-600">Orientation</span>
            <select className="border border-neutral-300 rounded px-1 py-0.5" value={filterOrientation} onChange={e=>setFilterOrientation(e.target.value as any)}>
              <option value="all">All</option>
              <option value="landscape">Landscape</option>
              <option value="portrait">Portrait</option>
              <option value="square">Square</option>
            </select>
          </label>
          <label className="flex items-center gap-1">
            <span className="text-neutral-600">Taken</span>
            <select className="border border-neutral-300 rounded px-1 py-0.5" value={filterRecent} onChange={e=>setFilterRecent(e.target.value as any)}>
              <option value="all">Any time</option>
              <option value="7d">Last 7d</option>
              <option value="30d">Last 30d</option>
            </select>
          </label>
          <label className="ml-auto flex items-center gap-1">
            <input type="checkbox" checked={preserveCrop} onChange={e=>setPreserveCrop(e.target.checked)} />
            <span className="text-neutral-700">Preserve crop</span>
          </label>
        </div>
        <div className="p-3 overflow-auto flex-1">
          {loading ? (
            <div className="text-sm text-neutral-500">Loading candidates…</div>
          ) : (
            <div className="grid grid-cols-3 gap-2">
              {filtered.map((c, i) => (
                <button key={c.path + i}
                  className="aspect-square bg-neutral-100 rounded overflow-hidden border border-neutral-200 hover:ring-2 hover:ring-blue-500"
                  title={c.filename}
                  onClick={() => onPick(c, { preserveCrop })}
                >
                  {c.src ? <img src={c.src} alt={c.filename} className="w-full h-full object-cover"/> : <div className="w-full h-full"/>}
                </button>
              ))}
              {filtered.length === 0 && (
                <div className="text-sm text-neutral-500">No candidates found.</div>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
