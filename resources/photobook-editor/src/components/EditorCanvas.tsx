/*
Copilot prompt:
Render a page with absolutely positioned slots. Support drag to update objectPosition. Selected item shows a ring.
Scale via background-size percentage when item.scale is set.
*/

import React, { useRef, useState, useEffect, useCallback } from 'react';
import type { PageJson, PageItem } from '../api/types';
import { useSelection } from '../state/selection';

// --- ZoomSlider ---
function ZoomSlider({ value, onChange }: { value: number; onChange: (v: number) => void }) {
  return (
    <input
      type="range"
  min={0.25}
      max={2.0}
      step={0.01}
      value={value}
      onChange={e => onChange(Number(e.target.value))}
      aria-label="Zoom"
      className="w-24 mx-2"
    />
  );
}

// --- Filmstrip ---
function Filmstrip({ items, selected, onSelect, onReorder }: {
  items: PageItem[];
  selected: number;
  onSelect: (idx: number) => void;
  onReorder: (from: number, to: number) => void;
}) {
  const [dragIdx, setDragIdx] = useState(null as number | null);
  const [overIdx, setOverIdx] = useState(null as number | null);
  return (
    <div className="flex gap-2 py-2 bg-neutral-100 rounded">
      {items.map((it, i) => (
        <div key={i}
          className={`w-16 h-16 rounded overflow-hidden border cursor-pointer ${selected === i ? 'border-blue-500' : 'border-neutral-300'} ${dragIdx === i ? 'opacity-50' : ''}`}
          onPointerDown={() => setDragIdx(i)}
          onPointerUp={() => {
            if (dragIdx !== null && overIdx !== null && dragIdx !== overIdx) {
              onReorder(dragIdx, overIdx);
            }
            setDragIdx(null); setOverIdx(null);
          }}
          onPointerEnter={() => dragIdx !== null && setOverIdx(i)}
          onClick={() => onSelect(i)}
        >
          {(() => { const u = (it as any).web || (it as any).webSrc || it.src; return u ? <img src={u} alt="thumb" className="w-full h-full object-cover" /> : <div className="w-full h-full bg-neutral-200" /> })()}
        </div>
      ))}
    </div>
  );
}

// --- useObjectPosition ---
function useObjectPosition(initial: string) {
  const [pos, setPos] = useState(initial);
  const set = (x: number, y: number) => {
    const clamp = (v: number) => Math.max(0, Math.min(100, isNaN(v) ? 50 : v));
    setPos(`${clamp(x)}% ${clamp(y)}%`);
  };
  const nudge = (dx: number, dy: number) => {
    const [x, y] = pos.split(' ').map(s => parseFloat(s));
    set(x + dx, y + dy);
  };
  return [pos, set, nudge] as const;
}

type Props = { page: PageJson; onSave: (items: PageItem[]) => void; scale?: number; version?: number };

export default function EditorCanvas({ page, onSave, scale = 1, version = 0 }: Props) {
  const rootRef = useRef(null as HTMLDivElement | null);
  const { setSelected } = useSelection();
  const keyFor = (i: number) => `${page.n}:${i}`;
  const [pageSize, setPageSize] = useState({ w: 900, h: 600 });
  const [imgRatios, setImgRatios] = useState<Record<number, number>>({});

  // Draft state for items
  const [draftItems, setDraftItems] = useState(() => page.items.map(it => ({ ...it, scale: it.scale ?? 1 })) as PageItem[]); // scale default 1
  const [selectedIdx, setSelectedIdx] = useState(0);

  // Reset draft when page or external version changes
  useEffect(() => {
    setDraftItems(page.items.map(it => ({ ...it, scale: it.scale ?? 1 })) as PageItem[]);
    setSelectedIdx(0);
    setSelected(keyFor(0));
  }, [page.n, version]);

  // Track page size for background-size pixel computation
  useEffect(() => {
    const measure = () => {
      const r = rootRef.current?.getBoundingClientRect();
      if (r) setPageSize({ w: r.width, h: r.height });
    };
    measure();
    window.addEventListener('resize', measure);
    return () => window.removeEventListener('resize', measure);
  }, []);

  // Load natural image ratios to ensure correct background-size regardless of EXIF/metadata
  useEffect(() => {
    draftItems.forEach((it, i) => {
      const u = (it as any).web || (it as any).webSrc || it.src;
      if (!u) return;
      if (imgRatios[i]) return;
      const img = new Image();
      img.onload = () => {
        if (img.naturalWidth > 0 && img.naturalHeight > 0) {
          setImgRatios(prev => ({ ...prev, [i]: img.naturalWidth / img.naturalHeight }));
        }
      };
      img.src = u;
    });
  }, [draftItems, imgRatios]);

  // Keyboard nudge handler
  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if (document.activeElement && ['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) return;
      if (selectedIdx < 0 || selectedIdx >= draftItems.length) return;
      let dx = 0, dy = 0;
      const step = e.shiftKey ? 5 : 1;
      if (e.key === 'ArrowLeft') dx = -step;
      if (e.key === 'ArrowRight') dx = step;
      if (e.key === 'ArrowUp') dy = -step;
      if (e.key === 'ArrowDown') dy = step;
      if (dx !== 0 || dy !== 0) {
        setDraftItems(items => items.map((it, i) => i === selectedIdx ? {
          ...it,
          objectPosition: updateObjectPosition(it.objectPosition ?? '50% 50%', dx, dy)
        } : it));
        e.preventDefault();
      }
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [selectedIdx, draftItems.length]);

  // Helper to update objectPosition
  function updateObjectPosition(pos: string, dx: number, dy: number) {
    let [x, y] = pos.split(' ').map(s => parseFloat(s));
    x = clampPct(x + dx); y = clampPct(y + dy);
    return `${x}% ${y}%`;
  }
  function clampPct(v: number) { return Math.max(0, Math.min(100, isNaN(v) ? 50 : v)); }

  // Drag-reorder handler
  const handleReorder = useCallback((from: number, to: number) => {
    if (from === to) return;
    setDraftItems(items => {
      const arr = [...items];
      const [moved] = arr.splice(from, 1);
      arr.splice(to, 0, moved);
      return arr;
    });
    setSelectedIdx(to);
  }, []);

  // Save handler
  const handleSave = () => {
    onSave(draftItems);
  };

  // Inject dynamic CSS for slot positions and background-position/size (cover × scale)
  useEffect(() => {
    const id = `page-style-${page.n}`;
    let css = '';
    draftItems.forEach((it, i) => {
      const s = page.slots[it.slotIndex] || { x: 0, y: 0, w: 1, h: 1 };
      const pos = it.objectPosition || '50% 50%';
      const zoom = (typeof it.scale === 'number' && isFinite(it.scale) && it.scale > 0) ? it.scale : 1;
      const slotW = pageSize.w * s.w;
      const slotH = pageSize.h * s.h;
  const imgRatio = (imgRatios[i] ?? (it as any).photo?.ratio) ?? (
        (it as any).photo?.width && (it as any).photo?.height
          ? (it as any).photo.width / (it as any).photo.height
          : null
      );
      const r = imgRatio && isFinite(imgRatio) && imgRatio > 0 ? imgRatio : (slotW / slotH || 1);
      const slotRatio = slotW / slotH || 1;
      const crop = it.crop === 'contain' ? 'contain' : 'cover';
      let sizeCss: string;
      if (crop === 'cover') {
        if (r > slotRatio) {
          // image wider than slot -> fit height, keep aspect by letting width auto
          sizeCss = `auto ${Math.max(1, Math.round(slotH * zoom))}px`;
        } else {
          // image taller/narrower -> fit width
          sizeCss = `${Math.max(1, Math.round(slotW * zoom))}px auto`;
        }
      } else {
        // contain
        if (r > slotRatio) {
          // image wider -> fit width
          sizeCss = `${Math.max(1, Math.round(slotW * zoom))}px auto`;
        } else {
          // image taller -> fit height
          sizeCss = `auto ${Math.max(1, Math.round(slotH * zoom))}px`;
        }
      }
      css += `.slot-wrap-${page.n}-${i}{left:${s.x * 100}%;top:${s.y * 100}%;width:${s.w * 100}%;height:${s.h * 100}%;}`;
      css += `.slot-bg-${page.n}-${i}{background-position:${pos};background-size:${sizeCss};}`;
    });
    let el = document.getElementById(id) as HTMLStyleElement | null;
    if (!el) {
      el = document.createElement('style');
      el.id = id;
      document.head.appendChild(el);
    }
    el.textContent = css;
  }, [page.n, draftItems, pageSize.w, pageSize.h, imgRatios]);

  return (
    <div className="flex flex-col gap-4">
      <Filmstrip
        items={draftItems}
        selected={selectedIdx}
        onSelect={setSelectedIdx}
        onReorder={handleReorder}
      />
  <div ref={rootRef} className="relative bg-white shadow border border-neutral-200 w-[900px] h-[600px]">
        {draftItems.map((it, i) => {
          const s = page.slots[it.slotIndex] || { x: 0, y: 0, w: 1, h: 1 };
          const isSel = selectedIdx === i;
          const pos = it.objectPosition || '50% 50%';
          const zoom = (typeof it.scale === 'number' && isFinite(it.scale) && it.scale > 0) ? it.scale : 1;
          return (
            <div key={i}
              className={`absolute overflow-hidden slot-wrap-${page.n}-${i} ${isSel ? 'ring-2 ring-blue-500' : 'ring-1 ring-neutral-200'}`}
              onMouseDown={() => { setSelectedIdx(i); setSelected(keyFor(i)); }}
            >
              {(() => { const u = (it as any).web || (it as any).webSrc || it.src; return u ? (
                <div
                  className={`w-full h-full slot-bg-${page.n}-${i} bg-no-repeat cursor-grab`}
                  style={{ backgroundImage: `url(${u})` }}
                  onMouseDown={e => {
                    // Use the slot wrapper rect (unscaled) for consistent percentage deltas
                    const slotEl = (e.currentTarget.parentElement as HTMLElement) ?? (e.currentTarget as HTMLElement);
                    const r = slotEl.getBoundingClientRect();
                    const startX = e.clientX, startY = e.clientY;
                    const [ox, oy] = pos.split(' ').map(s => parseFloat(s));
                    function onMove(ev: MouseEvent) {
                      const dx = ((ev.clientX - startX) / r.width) * 100;
                      const dy = ((ev.clientY - startY) / r.height) * 100;
                      setDraftItems(items => items.map((item, idx) => idx === i ? {
                        ...item,
                        objectPosition: `${clampPct(ox + dx)}% ${clampPct(oy + dy)}%`
                      } : item));
                    }
                    function onUp() {
                      window.removeEventListener('mousemove', onMove);
                      window.removeEventListener('mouseup', onUp);
                    }
                    window.addEventListener('mousemove', onMove);
                    window.addEventListener('mouseup', onUp);
                  }}
                />
              ) : (
                <div className="w-full h-full bg-neutral-100" />
              )})()}
              {/* Zoom slider */}
              <div className="absolute bottom-2 left-2 bg-white/80 rounded px-2 py-1 shadow flex items-center">
                <ZoomSlider value={zoom} onChange={v => setDraftItems(items => items.map((item, idx) => idx === i ? { ...item, scale: Math.max(0.25, v) } : item))} />
                <span className="text-xs ml-2">{(zoom * 100).toFixed(0)}%</span>
              </div>
            </div>
          );
        })}
      </div>
      <button className="mt-2 px-4 py-2 bg-blue-600 text-white rounded shadow" onClick={handleSave}>Save</button>
    </div>
  );
}
