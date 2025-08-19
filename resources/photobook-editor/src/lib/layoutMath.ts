export type Fit = 'cover' | 'contain';
export type Align = { x: number; y: number };
export type Offset = { x: number; y: number };

export const clamp = (v: number, a: number, b: number) => Math.max(a, Math.min(b, v));
export const isFinitePos = (x: any) => Number.isFinite(x) && x > 0;

export function fitMath(slotW: number, slotH: number, iw: number, ih: number, fit: Fit, zoom: number) {
  if (iw <= 0 || ih <= 0 || slotW <= 0 || slotH <= 0) {
    return { fw: slotW, fh: slotH, overflowX: 0, overflowY: 0, scale: 1 };
  }
  const sx = slotW / iw;
  const sy = slotH / ih;
  const base = fit === 'cover' ? Math.max(sx, sy) : Math.min(sx, sy);
  const scale = base * (zoom > 0 ? zoom : 1);
  const fw = iw * scale;
  const fh = ih * scale;
  return {
    fw, fh,
    overflowX: Math.max(0, fw - slotW),
    overflowY: Math.max(0, fh - slotH),
    scale
  };
}

export function alignOffsetToPanPx(align: Align, offset: Offset, overflowX: number, overflowY: number, slotW: number, slotH: number) {
  const panX = (overflowX / 2) * (align?.x ?? 0) + (offset?.x ?? 0) * slotW;
  const panY = (overflowY / 2) * (align?.y ?? 0) + (offset?.y ?? 0) * slotH;
  return { panX, panY };
}

export function solveAlignOffset(
  desiredPanX: number, desiredPanY: number,
  overflowX: number, overflowY: number,
  startOffset: Offset, slotW: number, slotH: number
) {
  let ax = 0, ay = 0, offX = startOffset.x, offY = startOffset.y;

  if (overflowX > 1e-6) {
    const r = overflowX / 2;
    const t = (desiredPanX - startOffset.x * slotW) / r;
    const c = clamp(t, -1, 1);
    const used = c * r + startOffset.x * slotW;
    ax = c; offX = startOffset.x + (desiredPanX - used) / slotW;
  } else {
    offX = startOffset.x + desiredPanX / slotW;
  }
  if (overflowY > 1e-6) {
    const r = overflowY / 2;
    const t = (desiredPanY - startOffset.y * slotH) / r;
    const c = clamp(t, -1, 1);
    const used = c * r + startOffset.y * slotH;
    ay = c; offY = startOffset.y + (desiredPanY - used) / slotH;
  } else {
    offY = startOffset.y + desiredPanY / slotH;
  }
  return { align: { x: ax, y: ay }, offset: { x: offX, y: offY } };
}
