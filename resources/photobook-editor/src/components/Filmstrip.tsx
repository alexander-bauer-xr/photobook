import React, { useState } from 'react';

export type FilmstripItem = {
  slotIndex: number;
  src?: string;
  web?: string;
  webSrc?: string;
  _error?: boolean;
  [key: string]: any;
};

export default function Filmstrip({
  items, selected, onSelect, onReorder
}: {
  items: FilmstripItem[];
  selected: number;
  onSelect: (idx: number) => void;
  onReorder: (from: number, to: number) => void;
}) {
  const [dragIdx, setDragIdx] = useState<number | null>(null);
  const [overIdx, setOverIdx] = useState<number | null>(null);

  const getSrc = (it: FilmstripItem) => (it as any).web || (it as any).webSrc || it.src || '';

  return (
    <div className="flex gap-2 py-2 bg-neutral-100 rounded items-center overflow-x-auto">
      {items.map((it, i) => {
        const u = getSrc(it);
        return (
          <div
            key={i}
            className={[
              'relative w-16 h-16 rounded overflow-hidden border cursor-pointer select-none',
              selected === i ? 'border-blue-500 ring-2 ring-blue-300' : 'border-neutral-300',
              dragIdx === i ? 'opacity-50' : '',
              overIdx === i ? 'outline outline-2 outline-green-500' : ''
            ].join(' ')}
            onPointerDown={(e) => { e.preventDefault(); setDragIdx(i); setOverIdx(null); }}
            onPointerEnter={() => { if (dragIdx !== null && dragIdx !== i) setOverIdx(i); }}
            onPointerUp={() => {
              if (dragIdx !== null && overIdx !== null && dragIdx !== overIdx) onReorder(dragIdx, overIdx);
              setDragIdx(null); setOverIdx(null);
            }}
            onClick={(e) => { e.preventDefault(); onSelect(i); }}
            style={{ touchAction: 'none' }}
            title={`Slot ${it.slotIndex}`}
          >
            {u ? (
              <img src={u} alt="" className="w-full h-full object-cover pointer-events-none" draggable={false} />
            ) : (
              <div className="w-full h-full bg-neutral-200" />
            )}
          </div>
        );
      })}
    </div>
  );
}
