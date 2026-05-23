import React, { useRef, useState, useEffect, useCallback, useMemo } from 'react';
import Filmstrip from './Filmstrip';
import SlotView from './SlotView';
import { fitMath, alignOffsetToPanPx, solveAlignOffset, clamp, isFinitePos } from '../lib/layoutMath';
import { Badge } from './ui/badge';
import { Button } from './ui/button';
import { normalizePhotobookItem } from '../features/photobook/model/photobook.normalizers';

/* =========================================================
   useUndoRedo (inline) – batching & limits
   ========================================================= */
function useUndoRedo<T>(initial: T, limit = 100) {
  const [present, setPresent] = useState<T>(initial);
  const pastRef = useRef<T[]>([]);
  const futureRef = useRef<T[]>([]);
  const idleTimer = useRef<number | null>(null);

  const set = useCallback((updater: T | ((prev: T) => T)) => {
    setPresent(prev => (typeof updater === 'function' ? (updater as any)(prev) : updater));
  }, []);

  const commit = useCallback((snapshot?: T) => {
    setPresent(curr => {
      const next = (snapshot ?? curr);
      const past = pastRef.current;
      if (past.length >= limit) past.shift();
      past.push(curr);
      futureRef.current = [];
      return next;
    });
  }, [limit]);

  const scheduleCommit = useCallback((ms = 300) => {
    if (idleTimer.current) window.clearTimeout(idleTimer.current);
    idleTimer.current = window.setTimeout(() => { idleTimer.current = null; commit(); }, ms);
  }, [commit]);

  const undo = useCallback(() => {
    const past = pastRef.current;
    if (past.length === 0) return;
    setPresent(curr => {
      const prev = past.pop() as T;
      futureRef.current.unshift(curr);
      return prev;
    });
  }, []);

  const redo = useCallback(() => {
    const future = futureRef.current;
    if (future.length === 0) return;
    setPresent(curr => {
      const next = future.shift() as T;
      pastRef.current.push(curr);
      return next;
    });
  }, []);

  const canUndo = pastRef.current.length > 0;
  const canRedo = futureRef.current.length > 0;

  return { state: present, set, commit, scheduleCommit, undo, redo, canUndo, canRedo };
}

/* =========================================================
   Types
   ========================================================= */
type Fit = 'cover' | 'contain';
type Align = { x: number; y: number };   // -1..1
type Offset = { x: number; y: number };  // Slot-Einheiten

type Slot = { x: number; y: number; w: number; h: number }; // normiert [0..1]
type PhotoRef = { path: string; filename?: string };

type AnyItem = {
  id?: string | number;
  slotIndex: number;

  // Preview-Quellen (UI)
  src?: string;        // file://…
  web?: string;        // http(s)
  webSrc?: string;     // legacy alias

  // Persistente Foto-Identität
  photo?: PhotoRef;

  // Kanonisch
  fit?: Fit;
  align?: Align;
  offset?: Offset;
  zoom?: number;        // > 0
  rotation?: number;    // deg
  auto?: boolean;

  // Legacy (server fields) that may appear on inbound items
  objectPosition?: string; // e.g., "50% 50%"
  crop?: 'cover' | 'contain';
  scale?: number;
  rotate?: number;

  // Metadata
  caption?: string;

  // Runtime
  _iw?: number; _ih?: number; // natural size
  _error?: boolean;           // load error
};

type Page = {
  n: number;
  slots: Slot[];
  items: AnyItem[];
  templateId?: string;   // <--- add this
};

type CoverMeta = {
  title?: string | null;
  subtitle?: string | null;
  date?: string | null;
  hasPhoto?: boolean | null;
};

type SaveOverride = {
  page: number;
  slotIndex: number;
  override: {
    fit: Fit; align: Align; offset: Offset; zoom: number; rotation: number; auto: boolean;
    photo?: PhotoRef;
  };
};

type Props = {
   page: Page;
   onSave?: (overrides: SaveOverride[]) => void;
   onChange?: (items: AnyItem[]) => void;
   onStructuralChange?: (items: AnyItem[]) => void;
   width?: number;
   height?: number;
   version?: number;
   saveUrl?: string;
   saveFetchInit?: Omit<RequestInit, 'method' | 'body'>;
   gapPx?: number;
   wheelZoomNeedsCtrl?: boolean;
   showHud?: boolean;
   interactive?: boolean;
   coverMeta?: CoverMeta;
   /** When set, bypasses ResizeObserver and uses these dimensions as pageSize (for bleed-scaled print mode). */
   pageSizeOverride?: { w: number; h: number };
 };

/* =========================================================
   Mathe (Parität zum PHP-Builder)
   ========================================================= */

/** Rotations-Pad-Faktor K gegen Self-Cropping */
// Prefer webSrc (relative) over web (which may be absolute) to avoid host mismatch
const getSrc = (it: AnyItem) => (it as any).webSrc || (it as any).web || it.src || '';

/* =========================================================
   Persist: Normalisieren, Validieren, Payload, POST
   ========================================================= */
function normalizeItem(it: AnyItem): AnyItem {
  return normalizePhotobookItem(it) as AnyItem;
}

function validateItem(it: AnyItem): string[] {
  const errs: string[] = [];
  if (it.fit !== 'cover' && it.fit !== 'contain') errs.push('fit');
  if (!it.align || !Number.isFinite(it.align.x) || !Number.isFinite(it.align.y) || it.align.x < -1 || it.align.x > 1 || it.align.y < -1 || it.align.y > 1) errs.push('align');
  if (!it.offset || !Number.isFinite(it.offset.x) || !Number.isFinite(it.offset.y)) errs.push('offset');
  if (!isFinitePos(it.zoom)) errs.push('zoom');
  if (!Number.isFinite(it.rotation)) errs.push('rotation');
  if (it.photo && typeof it.photo.path !== 'string') errs.push('photo.path');
  return errs;
}

function buildOverridesPayload(
  pageNumber: number,
  templateId: string | undefined,
  items: AnyItem[]
) {
  const pageKey = String(pageNumber);
  const normalized = items.map(normalizeItem);

  const itemsOut = normalized
    .slice()
    .sort((a, b) => a.slotIndex - b.slotIndex)
    .map(it => {
      const fit: Fit = it.fit === 'contain' ? 'contain' : 'cover';
      const align = { x: clamp(Number(it.align?.x ?? 0), -1, 1), y: clamp(Number(it.align?.y ?? 0), -1, 1) };
      const offset = { x: Number(it.offset?.x) || 0, y: Number(it.offset?.y) || 0 };
      const zoom = isFinitePos(it.zoom) ? Number(it.zoom) : 1;
      const rotation = Number.isFinite(Number(it.rotation)) ? ((Number(it.rotation) % 360) + 360) % 360 : 0;
      const caption =
        typeof it.caption === 'string'
          ? it.caption
          : typeof (it as any).caption === 'number'
            ? String((it as any).caption)
            : undefined;

      const canon = {
        slotIndex: it.slotIndex,
        fit, align, offset, zoom, rotation,
        auto: it.auto === true,
        ...(it.photo?.path ? { photo: { path: it.photo.path, ...(it.photo.filename ? { filename: it.photo.filename } : {}) } } : {}),
        ...(caption !== undefined ? { caption } : {}),
      };

      const legacy = {
        crop: fit,
        objectPosition: `${Math.round(50 + align.x * 50)}% ${Math.round(50 + align.y * 50)}%`,
        scale: zoom,
        rotate: rotation,
      };

      const maybeSrc = (it as any).web ?? (it as any).webSrc ?? (it as any).src
        ? { src: (it as any).web ?? (it as any).webSrc ?? (it as any).src }
        : {};

      return { ...canon, ...legacy, ...maybeSrc };
    });

  return {
    pages: {
      [String(pageNumber)]: {
        ...(templateId ? { templateId } : {}), // include if set
        items: itemsOut,
      },
    },
  };
}

async function postOverrides(saveUrl: string, payload: any, init?: Omit<RequestInit, 'method' | 'body'>) {
  const res = await fetch(saveUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...(init?.headers || {}) },
    body: JSON.stringify(payload),
    credentials: init?.credentials,
    mode: init?.mode,
    cache: init?.cache,
  });
  if (!res.ok) {
    const t = await res.text().catch(() => '');
    throw new Error(`Save failed: ${res.status} ${res.statusText} ${t}`);
  }
  return res.json().catch(() => ({}));
}

/* (ZoomSlider & Filmstrip moved to dedicated files) */

/* =========================================================
   Main Component
   ========================================================= */
export default function EditorCanvas({
   page,
   onSave,
   onChange,
   onStructuralChange,
   width = 900,
   height = 600,
   version = 0,
   saveUrl,
   saveFetchInit,
   gapPx = 8,
   wheelZoomNeedsCtrl = true,
  showHud = true,
  interactive = true,
  coverMeta,
  pageSizeOverride,
}: Props) {
  const rootRef = useRef<HTMLDivElement | null>(null);
  const viewportRef = useRef<HTMLDivElement | null>(null);
  const [pageSize, setPageSize] = useState({ w: width, h: height });
  const [viewportSize, setViewportSize] = useState({ w: width, h: height });
  const {
    state: items,
    set: setItems,
    commit: commitItems,
    scheduleCommit,
    undo, redo, canUndo, canRedo
  } = useUndoRedo<AnyItem[]>(Array.isArray(page.items) ? page.items.map(normalizeItem) : []);

  const [selectedIdx, setSelectedIdx] = useState(0);
  const [saveState, setSaveState] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle');

  // Touch-Pinch
  const pointersRef = useRef<Map<number, { x: number; y: number }>>(new Map());

  // Layout-Snapshot je Item
  const layoutRef = useRef<Record<number, {
    slotLeft: number; slotTop: number; slotW: number; slotH: number;
    contentW: number; contentH: number; innerPad: number;
  }>>({});

  const isCoverPage = page?.templateId === 'cover' || (page as any)?.id === 'cover';
  const primarySlotHeight = Array.isArray(page.slots) && page.slots.length > 0
    ? (Number(page.slots[0]?.h) || 0.649)
    : 0.649;
  const normalizedCoverSlotHeight = isCoverPage
    ? Math.max(0.1, Math.min(0.9, primarySlotHeight || 0.649))
    : 0.649;
  const mergedCoverMeta = isCoverPage
    ? {
        title: coverMeta?.title ?? null,
        subtitle: coverMeta?.subtitle ?? null,
        date: coverMeta?.date ?? null,
        hasPhoto: coverMeta?.hasPhoto ?? null,
      }
    : null;

  // Reset bei Seitenwechsel/Version (und neue History-Basis)
  useEffect(() => {
    setItems(Array.isArray(page.items) ? page.items.map(normalizeItem) : []);
    commitItems();
    setSelectedIdx(0);
  }, [page.n, (page as any).id, version, setItems, commitItems]);

  const canvasSize = useMemo(() => {
    if (!interactive) return { w: width, h: height };
    const availableW = Math.max(1, viewportSize.w);
    const ratio = width / height;
    return {
      w: Math.max(1, Math.floor(availableW)),
      h: Math.max(1, Math.floor(availableW / ratio)),
    };
  }, [height, interactive, viewportSize.w, width]);

  // Größe messen
  useEffect(() => {
    // In non-interactive mode with an explicit override, skip the ResizeObserver
    // (the CSS scale transform would make getBoundingClientRect report the wrong size).
    if (!interactive && pageSizeOverride && pageSizeOverride.w > 0) return;

    const target = interactive ? viewportRef.current : rootRef.current;
    if (!target) return;
    const measure = () => {
      const r = target.getBoundingClientRect();
      if (interactive) setViewportSize({ w: r.width, h: r.height });
      else setPageSize({ w: r.width, h: r.height });
    };
    measure();
    const ResizeObserverCtor = window.ResizeObserver;
    if (!ResizeObserverCtor) {
      window.addEventListener('resize', measure);
      return () => window.removeEventListener('resize', measure);
    }
    const ro = new ResizeObserverCtor(measure);
    ro.observe(target);
    return () => ro.disconnect();
  }, [interactive, pageSizeOverride]);

  // Apply explicit pageSize override (used in bleed-scaled print mode)
  useEffect(() => {
    if (!interactive && pageSizeOverride && pageSizeOverride.w > 0) {
      setPageSize(pageSizeOverride);
    }
  }, [interactive, pageSizeOverride?.w, pageSizeOverride?.h]);

  useEffect(() => {
    if (interactive) setPageSize(canvasSize);
  }, [canvasSize, interactive]);

  // Debounced onChange
  useEffect(() => {
    if (!onChange) return;
    const t = setTimeout(() => onChange(items), 120);
    return () => clearTimeout(t);
  }, [items, onChange]);

  // Slot-Reorder inkl. slotIndex-Swap + Duplicate-Guard + Commit
  const handleReorder = useCallback((from: number, to: number) => {
    if (from === to) return;
    const arr = items.map(item => ({ ...item }));
    [arr[from], arr[to]] = [arr[to], arr[from]];
    const nextItems = arr.map((item, index) => ({ ...item, slotIndex: index }));
    setItems(nextItems);
    onStructuralChange?.(nextItems);
    setSelectedIdx(to);
    commitItems();
  }, [items, setItems, commitItems, onStructuralChange]);


  // Autosave (debounced)
  useEffect(() => {
    if (!saveUrl) return;
    setSaveState('saving');
    const t = setTimeout(async () => {
      try {
        const normalized = items.map(normalizeItem);
        const errs = normalized.map(validateItem);
        const bad = errs.find(e => e.length);
        if (bad) throw new Error('validation failed: ' + JSON.stringify(errs));
        const payload = buildOverridesPayload(page.n, page.templateId, normalized);
        console.log('POST overrides payload →', payload);
        await postOverrides(saveUrl, payload, saveFetchInit);
        setSaveState('saved');
        setTimeout(() => setSaveState('idle'), 1200);
      } catch (e) {
        console.error(e);
        setSaveState('error');
      }
    }, 800);
    return () => clearTimeout(t);
  }, [items, page.n, saveUrl, saveFetchInit]);

  // Manueller Save
  const handleSave = useCallback(async () => {
  try {
    const normalized = items.map(normalizeItem);
    const errs = normalized.map(validateItem);
    const bad = errs.find(e => e.length);
    if (bad) throw new Error('validation failed: ' + JSON.stringify(errs));

    // Optional: keep legacy callback for older listeners
    if (onSave) {
      const legacyItems = normalized.map(it => {
        const ax = clamp(it.align?.x ?? 0, -1, 1);
        const ay = clamp(it.align?.y ?? 0, -1, 1);
        return {
          slotIndex: it.slotIndex,
          crop: (it.fit === 'contain' ? 'contain' : 'cover'),
          objectPosition: `${Math.round(50 + ax * 50)}% ${Math.round(50 + ay * 50)}%`,
          scale: isFinitePos(it.zoom) ? Number(it.zoom) : 1,
          rotate: Number.isFinite(Number(it.rotation)) ? Number(it.rotation) : 0,
          photo: it.photo ? { path: it.photo.path, ...(it.photo.filename ? { filename: it.photo.filename } : {}) } : null,
          src: (it as any).web ?? (it as any).webSrc ?? (it as any).src ?? null,
        };
      });
      onSave(legacyItems as any);
    }

    if (saveUrl) {
      setSaveState('saving');
      const payload = buildOverridesPayload(page.n, page.templateId, normalized);
      console.log('POST overrides payload →', payload);
      await postOverrides(saveUrl, payload, saveFetchInit);
      setSaveState('saved');
      setTimeout(() => setSaveState('idle'), 1200);
    }
  } catch (e) {
    console.error(e);
    setSaveState('error');
  }
}, [items, onSave, page.n, saveUrl, saveFetchInit]);

  // Keyboard Shortcuts & Nudge & Undo/Redo
  useEffect(() => {
    if (!interactive) return;
    const handler = (e: KeyboardEvent) => {
      // Undo/Redo
      const isMac = /Mac|iPhone|iPad/.test(navigator.platform);
      const meta = isMac ? e.metaKey : e.ctrlKey;
      if (meta && e.key.toLowerCase() === 'z') {
        if (e.shiftKey) { if (canRedo) redo(); }
        else { if (canUndo) undo(); }
        e.preventDefault(); return;
      }
      if (!isMac && e.ctrlKey && e.key.toLowerCase() === 'y') {
        if (canRedo) redo();
        e.preventDefault(); return;
      }

      if (!items.length) return;
      const tag = (document.activeElement?.tagName || '').toUpperCase();
      if (tag === 'INPUT' || tag === 'TEXTAREA') return;
      if (selectedIdx < 0 || selectedIdx >= items.length) return;

      const it = items[selectedIdx];
      const s = (page.slots || [])[it.slotIndex] || { x: 0, y: 0, w: 1, h: 1 };

      const slotW = Math.round(s.w * pageSize.w);
      const slotH = Math.round(s.h * pageSize.h);
      const contentW = Math.max(0, slotW - gapPx);
      const contentH = Math.max(0, slotH - gapPx);

      const iw = it._iw || 0, ih = it._ih || 0;
      const fit: Fit = it.fit || 'cover';
      const zoom = isFinitePos(it.zoom) ? it.zoom as number : 1;
      const { overflowX, overflowY } = fitMath(contentW, contentH, iw, ih, fit, zoom);

      let dx = 0, dy = 0;
      const stepPct = e.shiftKey ? 5 : 1;
      if (e.key === 'ArrowLeft') dx = -stepPct / 100 * contentW;
      if (e.key === 'ArrowRight') dx = stepPct / 100 * contentW;
      if (e.key === 'ArrowUp') dy = -stepPct / 100 * contentH;
      if (e.key === 'ArrowDown') dy = stepPct / 100 * contentH;

      if (dx !== 0 || dy !== 0) {
        const align: Align = it.align ?? { x: 0, y: 0 };
        const offset: Offset = it.offset ?? { x: 0, y: 0 };
        const { panX, panY } = alignOffsetToPanPx(align, offset, overflowX, overflowY, contentW, contentH);
        const solved = solveAlignOffset(panX + dx, panY + dy, overflowX, overflowY, offset, contentW, contentH);
        setItems(arr => arr.map((m, idx) => idx === selectedIdx ? { ...m, align: solved.align, offset: solved.offset, auto: false } : m));
        commitItems(); // diskrete Aktion => sofort committen
        e.preventDefault();
        return;
      }

      if (e.key.toLowerCase() === 'r') {
        const delta = e.shiftKey ? -90 : 90;
        setItems(arr => arr.map((m, idx) => idx === selectedIdx
          ? { ...m, rotation: (((m.rotation || 0) + delta) % 360 + 360) % 360, auto: false }
          : m));
        commitItems();
        e.preventDefault();
        return;
      }
      if (e.key.toLowerCase() === 'f') {
        setItems(arr => arr.map((m, idx) => idx === selectedIdx
          ? { ...m, fit: (m.fit || 'cover') === 'cover' ? 'contain' : 'cover', auto: false }
          : m));
        commitItems();
        e.preventDefault();
        return;
      }
      if (e.key === '0') {
        setItems(arr => arr.map((m, idx) => idx === selectedIdx
          ? { ...m, zoom: 1, align: { x: 0, y: 0 }, offset: { x: 0, y: 0 }, auto: false }
          : m));
        commitItems();
        e.preventDefault();
        return;
      }
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [interactive, items, selectedIdx, page.slots, pageSize.w, pageSize.h, gapPx, undo, redo, canUndo, canRedo, setItems, commitItems]);

  // Wheel-Zoom (Ctrl optional) mit Cursor-Fixpunkt & Rotation
  useEffect(() => {
    if (!interactive) return;
    const el = rootRef.current;
    if (!el) return;
    let lastTs = 0;

    const onWheel = (e: WheelEvent) => {
      if (selectedIdx < 0 || selectedIdx >= items.length) return;
      if (wheelZoomNeedsCtrl && !e.ctrlKey && !e.metaKey) return; // normales Scrollen erlauben
      const now = performance.now();
      if (now - lastTs < 40) return; // Rate-Limit ~25 Hz
      lastTs = now;
      e.preventDefault();

      const it = items[selectedIdx];
      const lay = layoutRef.current[selectedIdx];
      if (!lay) return;

      const rect = el.getBoundingClientRect();
      const cx = e.clientX - rect.left - lay.slotLeft - lay.innerPad - lay.contentW / 2;
      const cy = e.clientY - rect.top - lay.slotTop - lay.innerPad - lay.contentH / 2;

      const curZoom = isFinitePos(it.zoom) ? it.zoom as number : 1;
      const delta = -Math.sign(e.deltaY) * 0.1;
      const nextZoom = clamp(curZoom * (1 + delta), 0.05, 6);

      const iw = it._iw || 0, ih = it._ih || 0;
      const fit: Fit = it.fit || 'cover';
      const align: Align = it.align ?? { x: 0, y: 0 };
      const offset: Offset = it.offset ?? { x: 0, y: 0 };
      const rot = Number.isFinite(it.rotation) ? (it.rotation as number) : 0;

      const m0 = fitMath(lay.contentW, lay.contentH, iw, ih, fit, curZoom);
      const { panX, panY } = alignOffsetToPanPx(align, offset, m0.overflowX, m0.overflowY, lay.contentW, lay.contentH);

      // Cursor in Slot-Achsen (inverse Rotation)
      const rr = -rot * Math.PI / 180;
      const cxS = cx * Math.cos(rr) - cy * Math.sin(rr);
      const cyS = cx * Math.sin(rr) + cy * Math.cos(rr);

      const scaleRatio = nextZoom / curZoom;
      const desiredPanX = (panX - cxS) * scaleRatio + cxS;
      const desiredPanY = (panY - cyS) * scaleRatio + cyS;

      const m1 = fitMath(lay.contentW, lay.contentH, iw, ih, fit, nextZoom);
      const solved = solveAlignOffset(desiredPanX, desiredPanY, m1.overflowX, m1.overflowY, offset, lay.contentW, lay.contentH);

      setItems(list => list.map((m, idx) => idx === selectedIdx
        ? { ...m, zoom: nextZoom, align: solved.align, offset: solved.offset, auto: false }
        : m));
      scheduleCommit(350); // nach Zoom-Inaktivität committen
    };

    el.addEventListener('wheel', onWheel, { passive: false });
    return () => el.removeEventListener('wheel', onWheel as any);
  }, [interactive, items, selectedIdx, wheelZoomNeedsCtrl, setItems, scheduleCommit]);

  // Touch-Pinch (2 Pointer) inkl. Fixpunkt
  useEffect(() => {
    if (!interactive) return;
    const el = rootRef.current;
    if (!el) return;

    const getMid = (a: { x: number, y: number }, b: { x: number, y: number }) => ({ x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 });
    const dist = (a: { x: number, y: number }, b: { x: number, y: number }) => Math.hypot(a.x - b.x, a.y - b.y);

    let startZoom = 1, startPanX = 0, startPanY = 0, startRot = 0;
    let startCX = 0, startCY = 0, startD = 0;

    const onPointerDown = (e: PointerEvent) => {
      pointersRef.current.set(e.pointerId, { x: e.clientX, y: e.clientY });
      (e.target as HTMLElement).setPointerCapture?.(e.pointerId);
    };
    const onPointerMove = (e: PointerEvent) => {
      if (!pointersRef.current.has(e.pointerId)) return;
      pointersRef.current.set(e.pointerId, { x: e.clientX, y: e.clientY });
  const pts: { x: number; y: number }[] = Array.from(pointersRef.current.values());
      if (pts.length !== 2) return;

      e.preventDefault();
      if (selectedIdx < 0 || selectedIdx >= items.length) return;
      const it = items[selectedIdx];
      const lay = layoutRef.current[selectedIdx];
      if (!lay) return;

      const rect = el.getBoundingClientRect();
      const mid = getMid(pts[0], pts[1]);
      const cx = mid.x - rect.left - lay.slotLeft - lay.innerPad - lay.contentW / 2;
      const cy = mid.y - rect.top - lay.slotTop - lay.innerPad - lay.contentH / 2;

      if (startD === 0) {
        startD = dist(pts[0], pts[1]);
        startZoom = isFinitePos(it.zoom) ? it.zoom as number : 1;
        startRot = Number.isFinite(it.rotation) ? (it.rotation as number) : 0;

        const iw = it._iw || 0, ih = it._ih || 0;
        const fit: Fit = it.fit || 'cover';
        const align: Align = it.align ?? { x: 0, y: 0 };
        const offset: Offset = it.offset ?? { x: 0, y: 0 };

        const m0 = fitMath(lay.contentW, lay.contentH, iw, ih, fit, startZoom);
        const p0 = alignOffsetToPanPx(align, offset, m0.overflowX, m0.overflowY, lay.contentW, lay.contentH);
        startPanX = p0.panX; startPanY = p0.panY;

        const rr = -startRot * Math.PI / 180;
        const cxS = cx * Math.cos(rr) - cy * Math.sin(rr);
        const cyS = cx * Math.sin(rr) + cy * Math.cos(rr);
        startCX = cxS; startCY = cyS;
        return;
      }

      const curD = dist(pts[0], pts[1]);
      if (curD <= 0) return;
      const nextZoom = clamp(startZoom * (curD / startD), 0.05, 6);

      const iw = it._iw || 0, ih = it._ih || 0;
      const fit: Fit = it.fit || 'cover';
      const offset: Offset = it.offset ?? { x: 0, y: 0 };

      const scaleRatio = nextZoom / startZoom;
      const desiredPanX = (startPanX - startCX) * scaleRatio + startCX;
      const desiredPanY = (startPanY - startCY) * scaleRatio + startCY;

      const m1 = fitMath(lay.contentW, lay.contentH, iw, ih, fit, nextZoom);
      const solved = solveAlignOffset(desiredPanX, desiredPanY, m1.overflowX, m1.overflowY, offset, lay.contentW, lay.contentH);

      setItems(list => list.map((m, idx) => idx === selectedIdx
        ? { ...m, zoom: nextZoom, align: solved.align, offset: solved.offset, auto: false }
        : m));
    };
    const onPointerUp = (e: PointerEvent) => {
      pointersRef.current.delete(e.pointerId);
      (e.target as HTMLElement).releasePointerCapture?.(e.pointerId);
      if (pointersRef.current.size < 2) {
        startD = 0;
        commitItems(); // ein Eintrag pro Pinch
      }
    };

    el.addEventListener('pointerdown', onPointerDown);
    el.addEventListener('pointermove', onPointerMove, { passive: false });
    el.addEventListener('pointerup', onPointerUp);
    el.addEventListener('pointercancel', onPointerUp);
    return () => {
      el.removeEventListener('pointerdown', onPointerDown);
      el.removeEventListener('pointermove', onPointerMove as any);
      el.removeEventListener('pointerup', onPointerUp);
      el.removeEventListener('pointercancel', onPointerUp);
    };
  }, [interactive, items, selectedIdx, setItems, commitItems]);

  /* ==============================
     Render
     ============================== */
  return (
    <div className={interactive ? "flex h-full min-h-0 w-full min-w-0 flex-col gap-3" : "h-full w-full min-w-0"}>
      {interactive && (
        <div className="flex flex-none flex-wrap items-center gap-3 rounded-[26px] border border-neutral-200/80 bg-white/90 px-4 py-3 shadow-sm">
          <Filmstrip
            items={items}
            selected={selectedIdx}
            onSelect={setSelectedIdx}
            onReorder={handleReorder}
          />
          <div className="flex items-center gap-2">
            <Button
              size="sm"
              variant="secondary"
              onClick={undo}
              disabled={!canUndo}
              title="Undo (Ctrl/Cmd+Z)"
            >
              Undo
            </Button>
            <Button
              size="sm"
              variant="secondary"
              onClick={redo}
              disabled={!canRedo}
              title="Redo (Shift+Ctrl/Cmd+Z • Ctrl+Y)"
            >
              Redo
            </Button>
          </div>
          <div className="ml-auto flex flex-wrap items-center gap-2 text-xs text-neutral-600">
            <Badge>Drag to reorder</Badge>
            <Badge>Wheel to zoom</Badge>
            {saveState === 'saving' && <Badge>Saving…</Badge>}
            {saveState === 'saved' && <Badge variant="success">Saved</Badge>}
            {saveState === 'error' && <Badge variant="warning">Save error</Badge>}
          </div>
        </div>
      )}

      {/* Page Canvas */}
      <div
        ref={viewportRef}
        className={interactive ? "flex min-h-0 flex-1 items-start justify-center overflow-auto" : "h-full w-full"}
      >
        <div
          ref={rootRef}
          className={interactive
            ? "relative overflow-hidden rounded-[28px] border border-neutral-200 bg-white shadow-[0_18px_45px_rgba(15,23,42,0.08)]"
            : "relative h-full w-full overflow-hidden bg-white"}
          style={{
            width: interactive ? `${canvasSize.w}px` : '100%',
            height: interactive ? `${canvasSize.h}px` : '100%',
            maxWidth: interactive ? '100%' : '100%',
            maxHeight: interactive ? 'none' : '100%',
            userSelect: 'none',
          }}
        >
        {items.map((it, i) => {
          const slots: Slot[] = Array.isArray(page.slots) ? page.slots : [];
          const s = slots[it.slotIndex] || { x: 0, y: 0, w: 1, h: 1 };
          const isSel = selectedIdx === i;
          const isCoverItem = isCoverPage && i === 0;

          // ganzzahlige px (Parität/Anti-Hairline)
          const slotLeft = Math.round(s.x * pageSize.w);
          const slotTop = Math.round(s.y * pageSize.h);
          const slotRight = Math.round((s.x + s.w) * pageSize.w);
          const slotBottom = Math.round((s.y + s.h) * pageSize.h);
          const slotW = slotRight - slotLeft;
          const slotH = slotBottom - slotTop;

          const innerPad = isCoverItem ? 0 : Math.floor(gapPx / 2);
          const contentW = Math.max(0, slotW - gapPx);
          const contentH = Math.max(0, slotH - gapPx);

          // Memoisierte Größen/Trig
          const iw = it._iw || 0, ih = it._ih || 0;
          const fit = (it.fit || 'cover') as Fit;
          const zoom = isFinitePos(it.zoom) ? it.zoom as number : 1;
          const rot = Number.isFinite(it.rotation) ? (it.rotation as number) : 0;

          const math = fitMath(contentW, contentH, iw, ih, fit, zoom);

          // Pan immer in Slot-Achsen (Weltkoordinaten)
          const baseAlign: Align = it.align ?? { x: 0, y: 0 };
          const baseOffset: Offset = it.offset ?? { x: 0, y: 0 };
          const { panX, panY } = alignOffsetToPanPx(baseAlign, baseOffset, math.overflowX, math.overflowY, contentW, contentH);

          const src = getSrc(it);
          const loaded = iw > 0 && ih > 0;
          let coverItemHasPhoto: boolean | undefined;
          if (isCoverItem) {
            const metaHasPhoto = mergedCoverMeta?.hasPhoto;
            coverItemHasPhoto = typeof metaHasPhoto === 'boolean' ? metaHasPhoto : !!src;
          }

          // Layout Snapshot für Wheel/Pinch
          layoutRef.current[i] = { slotLeft, slotTop, slotW, slotH, contentW, contentH, innerPad };
          

          return (
            <React.Fragment key={i}>
              <SlotView
                pageNumber={page.n}
                idx={i}
                item={it as any}
                snapshot={{ slotLeft, slotTop, slotW, slotH, contentW, contentH, innerPad }}
                selected={interactive && isSel}
                showHud={showHud}
                interactive={interactive}
                onSelect={setSelectedIdx}
                onUpdateItem={(updater) => setItems(list => list.map((m2, idx2) => idx2 === i ? updater(m2 as any) as any : m2))}
                onCommit={commitItems}
                variant={isCoverItem ? 'cover' : 'default'}
                coverHasPhoto={isCoverItem ? (coverItemHasPhoto ?? undefined) : undefined}
                coverPlaceholderText="No cover photo selected"
              />
            </React.Fragment>
          );
        })}
        {isCoverPage && (
          <div
            className="absolute left-0 right-0"
            style={{
              top: `${normalizedCoverSlotHeight * 100}%`,
              bottom: 0,
              padding: '36px 48px',
              background: '#ffffff',
              display: 'flex',
              flexDirection: 'column',
              justifyContent: 'center',
              gap: '12px',
              pointerEvents: 'none',
              fontFamily: '"Inter", "Helvetica Neue", sans-serif',
              color: '#111827',
            }}
          >
            {mergedCoverMeta?.title && (
              <div style={{ fontSize: '34px', fontWeight: 600, letterSpacing: '0.02em', lineHeight: 1.1 }}>
                {mergedCoverMeta.title}
              </div>
            )}
            {mergedCoverMeta?.subtitle && (
              <div style={{ fontSize: '20px', fontWeight: 500, letterSpacing: '0.08em', textTransform: 'uppercase', color: '#6b7280' }}>
                {mergedCoverMeta.subtitle}
              </div>
            )}
            {mergedCoverMeta?.date && (
              <div style={{ fontSize: '18px', letterSpacing: '0.12em', textTransform: 'uppercase', color: '#9ca3af' }}>
                {mergedCoverMeta.date}
              </div>
            )}
          </div>
        )}
        </div>
      </div>

    </div>
  );
}