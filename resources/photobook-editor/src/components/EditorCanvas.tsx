import React, { useRef, useState, useEffect, useCallback } from 'react';
import { useSelection } from '../state/selection';

// ---------- Types ----------
type Fit = 'cover' | 'contain';
type Align = { x: number; y: number };
type Offset = { x: number; y: number };

type Slot = { x: number; y: number; w: number; h: number }; // normalized [0..1]
type Page = { n: number; slots: Slot[]; items: AnyItem[] };

type AnyItem = {
  id?: string | number;
  slotIndex: number;
  // image sources
  src?: string;
  web?: string;
  webSrc?: string;

  // canonical fields
  fit?: Fit;                         // 'cover' (default) | 'contain'
  align?: Align;                     // -1..1
  offset?: Offset;                   // slot units; unbounded
  zoom?: number;                     // >0; allow <1 (letterbox)
  rotation?: number;                 // clockwise deg
  auto?: boolean;                    // true if auto-generated

  // runtime helpers (not saved)
  _iw?: number; _ih?: number;        // natural image size
};

type Props = {
  page: Page | any;
  onSave: (overrides: Array<{
    page: number;
    slotIndex: number;
    override: { fit: Fit; align: Align; offset: Offset; zoom: number; rotation: number; auto: boolean };
  }>) => void;
  onChange?: (items: AnyItem[]) => void;
  width?: number;
  height?: number;
  version?: number;
};

// ---------- Math helpers (MUST match Python) ----------
function clamp(v: number, a: number, b: number) { return Math.max(a, Math.min(b, v)); }

function fitMath(slotW: number, slotH: number, iw: number, ih: number, fit: Fit, zoom: number) {
  if (iw <= 0 || ih <= 0 || slotW <= 0 || slotH <= 0) {
    return { fw: slotW, fh: slotH, overflowX: 0, overflowY: 0, scale: 1 };
  }
  const sx = slotW / iw;
  const sy = slotH / ih;
  const base = fit === 'cover' ? Math.max(sx, sy) : Math.min(sx, sy);
  const scale = base * (zoom > 0 ? zoom : 1);
  const fw = iw * scale;
  const fh = ih * scale;
  const overflowX = Math.max(0, fw - slotW);
  const overflowY = Math.max(0, fh - slotH);
  return { fw, fh, overflowX, overflowY, scale };
}

/** allocate pan delta (px) into align within [-1,1] first; spill over into offset (slot units) */
function solveAlignOffset(
  desiredPanX: number, desiredPanY: number,
  overflowX: number, overflowY: number,
  startOffset: Offset, slotW: number, slotH: number
) {
  let ax = 0, ay = 0, offX = startOffset.x, offY = startOffset.y;

  // X axis
  if (overflowX > 1e-6) {
    const axRange = overflowX / 2;
    const axTarget = (desiredPanX - startOffset.x * slotW) / axRange; // could be beyond [-1,1]
    const axClamped = clamp(axTarget, -1, 1);
    const usedFromAlign = axClamped * axRange + startOffset.x * slotW;
    const remainder = desiredPanX - usedFromAlign;
    ax = axClamped;
    offX = startOffset.x + (remainder / slotW);
  } else {
    // no overflow -> all pan goes to offset
    ax = 0;
    offX = startOffset.x + (desiredPanX / slotW);
  }

  // Y axis
  if (overflowY > 1e-6) {
    const ayRange = overflowY / 2;
    const ayTarget = (desiredPanY - startOffset.y * slotH) / ayRange;
    const ayClamped = clamp(ayTarget, -1, 1);
    const usedFromAlign = ayClamped * ayRange + startOffset.y * slotH;
    const remainder = desiredPanY - usedFromAlign;
    ay = ayClamped;
    offY = startOffset.y + (remainder / slotH);
  } else {
    ay = 0;
    offY = startOffset.y + (desiredPanY / slotH);
  }

  return { align: { x: ax, y: ay }, offset: { x: offX, y: offY } };
}

function alignOffsetToPanPx(align: Align, offset: Offset, overflowX: number, overflowY: number, slotW: number, slotH: number) {
  const panFromAlignX = (overflowX / 2) * (align?.x ?? 0);
  const panFromAlignY = (overflowY / 2) * (align?.y ?? 0);
  const panFromOffsetX = (offset?.x ?? 0) * slotW;
  const panFromOffsetY = (offset?.y ?? 0) * slotH;
  return {
    panX: panFromAlignX + panFromOffsetX,
    panY: panFromAlignY + panFromOffsetY
  };
}

function degToK(thetaDeg: number) {
  const r = (thetaDeg * Math.PI) / 180;
  // pad factor so a slot-sized viewport rotated by theta never self-crops inside the pad
  return Math.abs(Math.cos(r)) + Math.abs(Math.sin(r)); // in [1, sqrt(2)]
}

function getSrc(it: AnyItem) {
  return (it as any).web || (it as any).webSrc || it.src;
}

// ---------- UI bits ----------
function ZoomSlider({ value, onChange, min = 0.05, max = 6 }: { value: number; onChange: (v: number) => void; min?: number; max?: number }) {
  return (
    <input
      type="range"
      min={min}
      max={max}
      step={0.01}
      value={value}
      onChange={e => onChange(Number(e.target.value))}
      aria-label="Zoom"
      className="w-28 mx-2"
    />
  );
}

function Filmstrip({ items, selected, onSelect, onReorder }: {
  items: AnyItem[];
  selected: number;
  onSelect: (idx: number) => void;
  onReorder: (from: number, to: number) => void;
}) {
  const [dragIdx, setDragIdx] = useState<number | null>(null);
  const [overIdx, setOverIdx] = useState<number | null>(null);

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
            {u
              ? <img src={u} alt="thumb" className="w-full h-full object-cover pointer-events-none" draggable={false} />
              : <div className="w-full h-full bg-neutral-200" />}
          </div>
        );
      })}
    </div>
  );
}

// ---------- Main Component ----------
export default function EditorCanvas({ page, onSave, onChange, width = 900, height = 600, version = 0 }: Props) {
  const rootRef = useRef<HTMLDivElement | null>(null);
  const [pageSize, setPageSize] = useState({ w: width, h: height });
  const [items, setItems] = useState<AnyItem[]>(() => (Array.isArray(page.items) ? page.items : []));
  const [selectedIdx, setSelectedIdx] = useState(0);
  const GAP_PX = 8; // matches PDF inner gap (both sides combined via padding on inner)

  const { setSelected } = useSelection();

  // Reset on page/version
  useEffect(() => {
    setItems(Array.isArray(page.items) ? page.items : []);
    setSelectedIdx(0);
    try { setSelected(`${page.n}:0`); } catch {}
  }, [page.n, version]);

  // Track actual rendered page size
  useEffect(() => {
    const measure = () => {
      const r = rootRef.current?.getBoundingClientRect();
      if (r) setPageSize({ w: r.width, h: r.height });
    };
    measure();
    window.addEventListener('resize', measure);
    return () => window.removeEventListener('resize', measure);
  }, []);

  // Reorder handler
  const handleReorder = useCallback((from: number, to: number) => {
    if (from === to) return;
    setItems((prev) => {
      const arr = [...prev];
      const [moved] = arr.splice(from, 1);
      arr.splice(to, 0, moved);
      onChange?.(arr);
      return arr;
    });
    setSelectedIdx(to);
    try { setSelected(`${page.n}:${to}`); } catch {}
  }, [onChange, page.n, setSelected]);

  // Keyboard nudge: 1% (Shift = 5%) of slot size in px
  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if (!items.length) return;
      if (document.activeElement && ['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) return;
      if (selectedIdx < 0 || selectedIdx >= items.length) return;

      const it = items[selectedIdx];
      const s = (Array.isArray(page.slots) ? page.slots : [])[it.slotIndex] || { x: 0, y: 0, w: 1, h: 1 };
      const slotW = s.w * pageSize.w - GAP_PX;
      const slotH = s.h * pageSize.h - GAP_PX;

      const iw = it._iw || 0, ih = it._ih || 0;
      const fit: Fit = it.fit || 'cover';
      const zoom = typeof it.zoom === 'number' && isFinite(it.zoom!) && it.zoom! > 0 ? (it.zoom as number) : 1;
      const { overflowX, overflowY } = fitMath(slotW, slotH, iw, ih, fit, zoom);

      let dx = 0, dy = 0;
      const stepPct = e.shiftKey ? 5 : 1;
      if (e.key === 'ArrowLeft') dx = -stepPct / 100 * slotW;
      if (e.key === 'ArrowRight') dx = stepPct / 100 * slotW;
      if (e.key === 'ArrowUp') dy = -stepPct / 100 * slotH;
      if (e.key === 'ArrowDown') dy = stepPct / 100 * slotH;
      if (dx === 0 && dy === 0) return;

      // compute current pan and desired pan
      const align: Align = it.align ?? { x: 0, y: 0 };
      const offset: Offset = it.offset ?? { x: 0, y: 0 };
      const { panX, panY } = alignOffsetToPanPx(align, offset, overflowX, overflowY, slotW, slotH);
      const desiredPanX = panX + dx;
      const desiredPanY = panY + dy;

      const solved = solveAlignOffset(desiredPanX, desiredPanY, overflowX, overflowY, offset, slotW, slotH);
      setItems(arr => arr.map((m, idx) => idx === selectedIdx ? { ...m, align: solved.align, offset: solved.offset, auto: false } : m));
      e.preventDefault();
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [items, selectedIdx, page.slots, pageSize.w, pageSize.h]);

  // Save → build overrides (only canonical fields)
  const handleSave = () => {
    const overrides = items.map((it) => {
      const fit: Fit = it.fit || 'cover';
      const align: Align = it.align ?? { x: 0, y: 0 };
      const offset: Offset = it.offset ?? { x: 0, y: 0 };
      const zoom = (typeof it.zoom === 'number' && isFinite(it.zoom!) && it.zoom! > 0) ? it.zoom as number : 1;
      const rotation = (typeof it.rotation === 'number' && isFinite(it.rotation!)) ? it.rotation as number : 0;
      return {
        page: page.n,
        slotIndex: it.slotIndex,
        override: { fit, align, offset, zoom, rotation, auto: false }
      };
    });
    onSave(overrides);
  };

  return (
    <div className="flex flex-col gap-4">
      <Filmstrip
        items={items}
        selected={selectedIdx}
        onSelect={(i) => { setSelectedIdx(i); try { setSelected(`${page.n}:${i}`); } catch {} }}
        onReorder={handleReorder}
      />

      {/* Page canvas */}
      <div ref={rootRef} className="relative bg-white shadow border border-neutral-200" style={{ width, height }}>
        {items.map((it, i) => {
          const slots: Slot[] = Array.isArray(page.slots) ? page.slots : [];
          const s = slots[it.slotIndex] || { x: 0, y: 0, w: 1, h: 1 };
          const isSel = selectedIdx === i;

          // slot rect in px
          const slotLeft = s.x * pageSize.w;
          const slotTop = s.y * pageSize.h;
          const slotW = s.w * pageSize.w;
          const slotH = s.h * pageSize.h;

          const innerPad = GAP_PX / 2;
          const contentW = Math.max(0, slotW - GAP_PX);
          const contentH = Math.max(0, slotH - GAP_PX);

          const fit: Fit = it.fit || 'cover';
          const zoom = (typeof it.zoom === 'number' && isFinite(it.zoom!) && it.zoom! > 0) ? it.zoom as number : 1;
          const rot = (typeof it.rotation === 'number' && isFinite(it.rotation!)) ? it.rotation as number : 0;
          const align: Align = it.align ?? { x: 0, y: 0 };
          const offset: Offset = it.offset ?? { x: 0, y: 0 };
          const iw = it._iw || 0, ih = it._ih || 0;

          const m = fitMath(contentW, contentH, iw, ih, fit, zoom);
          const { panX, panY } = alignOffsetToPanPx(align, offset, m.overflowX, m.overflowY, contentW, contentH);
          const K = degToK(rot);
          const padW = contentW * K;
          const padH = contentH * K;

          const src = getSrc(it);

          return (
            <div
              key={i}
              className={isSel ? 'ring-2 ring-blue-500' : 'ring-1 ring-neutral-200'}
              style={{
                position: 'absolute',
                left: `${slotLeft}px`,
                top: `${slotTop}px`,
                width: `${slotW}px`,
                height: `${slotH}px`,
                overflow: 'hidden',
                borderRadius: '2px'
              }}
              onMouseDown={() => { setSelectedIdx(i); try { setSelected(`${page.n}:${i}`); } catch {} }}
            >
              {/* Slot inner padding */}
              <div style={{ position: 'absolute', left: innerPad, top: innerPad, right: innerPad, bottom: innerPad, boxSizing: 'border-box' }}>
                {/* Rotation pad (prevents self-cropping when rotating the viewport) */}
                <div
                  style={{
                    position: 'absolute',
                    left: '50%',
                    top: '50%',
                    width: `${padW}px`,
                    height: `${padH}px`,
                    transform: `translate(-50%,-50%) rotate(${rot}deg)`,
                    transformOrigin: 'center center',
                    willChange: 'transform',
                  }}
                >
                  {/* Viewport = slot-sized content box centered inside the rot pad */}
                  <div
                    style={{
                      position: 'absolute',
                      left: '50%',
                      top: '50%',
                      width: `${contentW}px`,
                      height: `${contentH}px`,
                      transform: 'translate(-50%,-50%)',
                      transformOrigin: 'center center',
                    }}
                  >
                    {/* Pan layer: combine align + offset (px) */}
                    <div
                      style={{
                        position: 'absolute',
                        left: '50%',
                        top: '50%',
                        transform: `translate(-50%,-50%) translate(${panX}px, ${panY}px)`,
                        transformOrigin: 'center center',
                        willChange: 'transform',
                      }}
                    >
                      {/* Image: sized to the viewport; object-fit applies base scale; then extra zoom */}
                      {src ? (
                        <img
                          src={src}
                          alt=""
                          draggable={false}
                          className="select-none"
                          style={{
                            width: `${contentW}px`,
                            height: `${contentH}px`,
                            objectFit: fit as any,
                            objectPosition: '50% 50%',
                            transform: `scale(${zoom})`,
                            transformOrigin: 'center center',
                            cursor: 'grab',
                            userSelect: 'none',
                            display: 'block'
                          }}
                          onLoad={(ev) => {
                            const el = ev.currentTarget as HTMLImageElement;
                            const iw = el.naturalWidth || 0;
                            const ih = el.naturalHeight || 0;
                            if (iw > 0 && ih > 0) {
                              setItems(list => list.map((m, idx) => idx === i ? { ...m, _iw: iw, _ih: ih } : m));
                            }
                          }}
                          onMouseDown={(e) => {
                            e.preventDefault();
                            const startX = e.clientX, startY = e.clientY;
                            const startRot = rot;
                            const cos = Math.cos(-startRot * Math.PI / 180);
                            const sin = Math.sin(-startRot * Math.PI / 180);

                            // snapshot at start
                            const startAlign = align;
                            const startOffset = offset;
                            const { overflowX, overflowY } = fitMath(contentW, contentH, iw, ih, fit, zoom);
                            const startPan = alignOffsetToPanPx(startAlign, startOffset, overflowX, overflowY, contentW, contentH);

                            const imgEl = e.currentTarget as HTMLImageElement;
                            const prevCursor = imgEl.style.cursor;
                            imgEl.style.cursor = 'grabbing';

                            function onMove(ev: MouseEvent) {
                              const rawDx = ev.clientX - startX;
                              const rawDy = ev.clientY - startY;
                              // inverse-rotate drag vector into slot axes
                              const dx = rawDx * cos - rawDy * sin;
                              const dy = rawDx * sin + rawDy * cos;

                              const desiredPanX = startPan.panX + dx;
                              const desiredPanY = startPan.panY + dy;

                              const solved = solveAlignOffset(desiredPanX, desiredPanY, overflowX, overflowY, startOffset, contentW, contentH);
                              setItems(list => list.map((m, idx) => idx === i
                                ? { ...m, align: solved.align, offset: solved.offset, auto: false }
                                : m));
                            }
                            function onUp() {
                              window.removeEventListener('mousemove', onMove);
                              window.removeEventListener('mouseup', onUp);
                              imgEl.style.cursor = prevCursor || 'grab';
                            }
                            window.addEventListener('mousemove', onMove);
                            window.addEventListener('mouseup', onUp);
                          }}
                        />
                      ) : (
                        <div className="w-full h-full bg-neutral-100" />
                      )}
                    </div>
                  </div>
                </div>
              </div>

              {/* Controls */}
              <div className="absolute bottom-2 left-2 bg-white/85 rounded px-2 py-1 shadow flex items-center gap-2">
                <span className="text-xs text-neutral-600">Zoom</span>
                <ZoomSlider
                  value={zoom}
                  min={0.05}
                  max={6}
                  onChange={(v) => setItems(list => list.map((m, idx) => idx === i ? { ...m, zoom: v, auto: false } : m))}
                />
                <span className="text-xs w-10 text-right tabular-nums">{(zoom * 100).toFixed(0)}%</span>
              </div>
              <div className="absolute top-2 left-2 bg-white/85 rounded px-2 py-1 shadow flex items-center gap-1">
                <button
                  className="px-2 py-1 text-xs bg-neutral-200 rounded hover:bg-neutral-300"
                  onClick={() => setItems(list => list.map((m, idx) => idx === i ? { ...m, rotation: (((m.rotation || 0) - 90) % 360 + 360) % 360, auto: false } : m))}
                >⟲ 90°</button>
                <button
                  className="px-2 py-1 text-xs bg-neutral-200 rounded hover:bg-neutral-300"
                  onClick={() => setItems(list => list.map((m, idx) => idx === i ? { ...m, rotation: (((m.rotation || 0) + 90) % 360 + 360) % 360, auto: false } : m))}
                >⟳ 90°</button>
                <button
                  className="px-2 py-1 text-xs bg-neutral-200 rounded hover:bg-neutral-300"
                  onClick={() => setItems(list => list.map((m, idx) => {
                    if (idx !== i) return m;
                    const nextFit: Fit = (m.fit || 'cover') === 'cover' ? 'contain' : 'cover';
                    return { ...m, fit: nextFit, auto: false };
                  }))}
                  title="Toggle fit (cover/contain)"
                >
                  fit: {(it.fit || 'cover')}
                </button>
              </div>
            </div>
          );
        })}
      </div>

      <div className="flex gap-2">
        <button className="px-4 py-2 bg-blue-600 text-white rounded shadow" onClick={handleSave}>Save overrides</button>
        <button
          className="px-3 py-2 bg-neutral-200 rounded"
          onClick={() => {
            // normalize all current items (ensure defaults exist) and emit to onChange
            const normalized = items.map((it) => ({
              ...it,
              fit: it.fit || 'cover',
              align: it.align ?? { x: 0, y: 0 },
              offset: it.offset ?? { x: 0, y: 0 },
              zoom: (typeof it.zoom === 'number' && isFinite(it.zoom!) && it.zoom! > 0) ? it.zoom : 1,
              rotation: (typeof it.rotation === 'number' && isFinite(it.rotation!)) ? it.rotation : 0
            }));
            onChange?.(normalized);
          }}
        >
          Sync state
        </button>
      </div>
    </div>
  );
}
