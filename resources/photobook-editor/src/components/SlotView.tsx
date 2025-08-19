import React from 'react';
import ZoomSlider from './ZoomSlider';
import {
  fitMath,
  alignOffsetToPanPx,
  solveAlignOffset,
  clamp,
  isFinitePos,
} from '../lib/layoutMath';

// Minimal item type (mirrors EditorCanvas local AnyItem)
export type Item = {
  slotIndex: number;
  src?: string;
  web?: string;
  webSrc?: string;
  photo?: { path: string; filename?: string };
  fit?: 'cover' | 'contain';
  align?: { x: number; y: number };
  offset?: { x: number; y: number };
  zoom?: number;
  rotation?: number;
  auto?: boolean;
  _iw?: number;
  _ih?: number;
  _error?: boolean;
};

export type SlotSnapshot = {
  slotLeft: number;
  slotTop: number;
  slotW: number;
  slotH: number;
  contentW: number;
  contentH: number;
  innerPad: number;
};

const getSrc = (it: Item) =>
  (it as any).web || (it as any).webSrc || it.src || '';

export default function SlotView({
  pageNumber,
  idx,
  item,
  snapshot,
  selected,
  showHud,
  onSelect,
  onUpdateItem,
  onCommit,
}: {
  pageNumber: number;
  idx: number;
  item: Item;
  snapshot: SlotSnapshot;
  selected: boolean;
  showHud: boolean;
  onSelect: (idx: number) => void;
  onUpdateItem: (updater: (prev: Item) => Item) => void;
  onCommit: () => void;
}) {
  const { slotLeft, slotTop, slotW, slotH, contentW, contentH, innerPad } =
    snapshot;

  // Size & math
  const iw = item._iw || 0,
    ih = item._ih || 0;
  const fit = (item.fit || 'cover') as 'cover' | 'contain';
  const zoom = isFinitePos(item.zoom) ? (item.zoom as number) : 1;
  const rot = Number.isFinite(item.rotation) ? (item.rotation as number) : 0;

  const math = fitMath(contentW, contentH, iw, ih, fit, zoom);
  const baseAlign = item.align ?? { x: 0, y: 0 };
  const baseOffset = item.offset ?? { x: 0, y: 0 };
  const { panX, panY } = alignOffsetToPanPx(
    baseAlign,
    baseOffset,
    math.overflowX,
    math.overflowY,
    contentW,
    contentH
  );

  // Use final width to derive actual scale (includes base fit + zoom)
  // Safe even if math.scale is only base scale.
  const imgScale = iw > 0 ? math.fw / iw : 1;

  const src = getSrc(item);
  const loaded = iw > 0 && ih > 0;

  return (
    <div
      className={selected ? 'ring-2 ring-blue-500' : 'ring-1 ring-neutral-200'}
      style={{
        position: 'absolute',
        left: `${slotLeft}px`,
        top: `${slotTop}px`,
        width: `${slotW}px`,
        height: `${slotH}px`,
        overflow: 'hidden',
        borderRadius: '2px',
        willChange: 'transform',
        backfaceVisibility: 'hidden',
      }}
      onMouseDown={() => onSelect(idx)}
      title={`Seite ${pageNumber} – Slot ${item.slotIndex}`}
      tabIndex={0}
    >
      <div
        style={{
          position: 'absolute',
          left: innerPad,
          top: innerPad,
          right: innerPad,
          bottom: innerPad,
          boxSizing: 'border-box',
          overflow: 'hidden',
          background: '#fff',
        }}
      >
        {src && !item._error ? (
          <img
            src={src}
            alt=""
            draggable={false}
            className="select-none"
            width={loaded ? undefined : contentW}
            height={loaded ? undefined : contentH}
            style={{
              position: 'absolute',
              left: '50%',
              top: '50%',
              transform: `translate(-50%, -50%) translate(${Math.round(
                panX
              )}px, ${Math.round(panY)}px) rotate(${rot}deg) scale(${imgScale})`,
              transformOrigin: 'center center',
              cursor: loaded ? 'grab' : 'default',
              userSelect: 'none',
              display: 'block',
              backfaceVisibility: 'hidden',
              willChange: 'transform',
            }}
            onLoad={(ev) => {
              const el = ev.currentTarget as HTMLImageElement;
              const iwNat = el.naturalWidth || 0;
              const ihNat = el.naturalHeight || 0;
              if (iwNat > 0 && ihNat > 0) {
                onUpdateItem((prev) => ({
                  ...prev,
                  _iw: iwNat,
                  _ih: ihNat,
                  _error: false,
                }));
              }
            }}
            onError={() =>
              onUpdateItem((prev) => ({ ...prev, _error: true }))
            }
            onMouseDown={(e) => {
              if (!(iw > 0 && ih > 0)) return;
              e.preventDefault();
              const startX = e.clientX,
                startY = e.clientY;
              const startAlign = baseAlign;
              const startOffset = baseOffset;
              const startPan = alignOffsetToPanPx(
                startAlign,
                startOffset,
                math.overflowX,
                math.overflowY,
                contentW,
                contentH
              );
              const elImg = e.currentTarget as HTMLImageElement;
              const prevCursor = elImg.style.cursor;
              elImg.style.cursor = 'grabbing';
              let raf = 0;

              function onMove(ev: MouseEvent) {
                if (raf) return;
                raf = requestAnimationFrame(() => {
                  raf = 0;
                  const rawDx = ev.clientX - startX;
                  const rawDy = ev.clientY - startY;
                  const cos = Math.cos((-rot * Math.PI) / 180);
                  const sin = Math.sin((-rot * Math.PI) / 180);
                  const dx = rawDx * cos - rawDy * sin;
                  const dy = rawDx * sin + rawDy * cos;
                  const desiredPanX = startPan.panX + dx;
                  const desiredPanY = startPan.panY + dy;
                  const solved = solveAlignOffset(
                    desiredPanX,
                    desiredPanY,
                    math.overflowX,
                    math.overflowY,
                    startOffset,
                    contentW,
                    contentH
                  );
                  onUpdateItem((prev) => ({
                    ...prev,
                    align: solved.align,
                    offset: solved.offset,
                    auto: false,
                  }));
                });
              }

              function onUp() {
                window.removeEventListener('mousemove', onMove);
                window.removeEventListener('mouseup', onUp);
                if (raf) cancelAnimationFrame(raf);
                elImg.style.cursor = prevCursor || 'grab';
                onCommit();
              }

              window.addEventListener('mousemove', onMove);
              window.addEventListener('mouseup', onUp);
            }}
            title="Drag = verschieben · Mausrad/Ctrl+Wheel = Zoom · R/Shift+R = rotieren · F = fit"
          />
        ) : (
          <div className="w-full h-full grid place-items-center bg-neutral-100 text-neutral-500 text-xs">
            {item._error ? 'Bild konnte nicht geladen werden' : 'Kein Bild'}
          </div>
        )}
      </div>

      {/* Controls */}
      <div className="absolute bottom-2 left-2 bg-white/85 rounded px-2 py-1 shadow flex items-center gap-2">
        <span className="text-xs text-neutral-600">Zoom</span>
        <ZoomSlider
          value={zoom}
          min={0.05}
          max={6}
          onChange={(v) =>
            onUpdateItem((prev) => ({ ...prev, zoom: v, auto: false }))
          }
        />
        <span className="text-xs w-10 text-right tabular-nums">
          {(zoom * 100).toFixed(0)}%
        </span>
      </div>

      <div className="absolute top-2 left-2 bg-white/85 rounded px-2 py-1 shadow flex items-center gap-1">
        <button
          className="px-2 py-1 text-xs bg-neutral-200 rounded hover:bg-neutral-300"
          onClick={() => {
            onUpdateItem((prev) => ({
              ...prev,
              rotation:
                (((prev.rotation || 0) - 90) % 360 + 360) % 360,
              auto: false,
            }));
            onCommit();
          }}
          title="⟲ 90° (Shift+R)"
          aria-label="Rotate -90°"
        >
          ⟲ 90°
        </button>
        <button
          className="px-2 py-1 text-xs bg-neutral-200 rounded hover:bg-neutral-300"
          onClick={() => {
            onUpdateItem((prev) => ({
              ...prev,
              rotation:
                (((prev.rotation || 0) + 90) % 360 + 360) % 360,
              auto: false,
            }));
            onCommit();
          }}
          title="⟳ 90° (R)"
          aria-label="Rotate +90°"
        >
          ⟳ 90°
        </button>
        <button
          className="px-2 py-1 text-xs bg-neutral-200 rounded hover:bg-neutral-300"
          onClick={() => {
            onUpdateItem((prev) => ({
              ...prev,
              fit: (prev.fit || 'cover') === 'cover' ? 'contain' : 'cover',
              auto: false,
            }));
            onCommit();
          }}
          title="Toggle fit (F)"
          aria-label="Toggle fit"
        >
          fit: {fit}
        </button>
        <button
          className="px-2 py-1 text-xs bg-neutral-200 rounded hover:bg-neutral-300"
          onClick={() => {
            onUpdateItem((prev) => ({
              ...prev,
              align: { x: 0, y: 0 },
              offset: { x: 0, y: 0 },
              zoom: 1,
              rotation: 0,
              auto: false,
            }));
            onCommit();
          }}
          title="Reset (0 setzt Zoom)"
          aria-label="Reset"
        >
          Reset
        </button>
        <button
          className="px-2 py-1 text-xs bg-neutral-200 rounded hover:bg-neutral-300"
          onClick={() => {
            onUpdateItem((prev) => ({ ...prev, auto: true }));
            onCommit();
          }}
          title="Auto (ML/Default wieder zulassen)"
          aria-label="Auto mode"
        >
          Auto
        </button>
      </div>

      {showHud && (
        <div className="absolute top-2 right-2 bg-white/85 rounded px-2 py-1 shadow text-[10px] leading-tight text-neutral-700">
          <div>fit: {fit}</div>
          <div>rot: {rot.toFixed(0)}°</div>
          <div>
            align:{' '}
            {(item.align?.x ?? 0).toFixed(2)},{' '}
            {(item.align?.y ?? 0).toFixed(2)}
          </div>
          <div>
            offset:{' '}
            {(item.offset?.x ?? 0).toFixed(3)},{' '}
            {(item.offset?.y ?? 0).toFixed(3)}
          </div>
          <div>
            ovX/ovY: {math.overflowX.toFixed(1)}/{math.overflowY.toFixed(1)}
          </div>
        </div>
      )}
    </div>
  );
}
