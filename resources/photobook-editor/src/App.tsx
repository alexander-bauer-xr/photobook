import React, { useEffect, useMemo, useRef, useState } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { usePages } from './hooks/usePages';
import EditorCanvas from './components/EditorCanvas';
import Sidebar from './components/Sidebar';
import ReplaceDrawer from './components/ReplaceDrawer';
import PdfReadyModal from './components/PdfReadyModal';
import SettingsPanel from './components/SettingsPanel';
import { api } from './api/client';
import { useTemplates } from './hooks/useTemplates';
import { PB } from './lib/api';
// Using dynamic page typing to allow merged overrides shape

const FEEDBACK_ACTIONS = [
  { value: 'like', label: 'Like' },
  { value: 'dislike', label: 'Dislike' },
  { value: 'faces-cropped', label: 'Faces Cropped' },
  { value: 'too-repetitive', label: 'Too Repetitive' },
  { value: 'low-confidence', label: 'Low Confidence' },
] as const;

const DEFAULT_FEEDBACK_ACTION = FEEDBACK_ACTIONS[0]?.value ?? 'like';

const qc = new QueryClient();

const defaultCoverDateText = () => new Date().toLocaleString('en-US', { month: 'long', year: 'numeric' });

export default function App() {
  return <QueryClientProvider client={qc}><Root /></QueryClientProvider>;
}

function Root() {
  const [folder, setFolder] = useState('');
  const [pageIdx, setPageIdx] = useState(0);
  const [albums, setAlbums] = useState([] as { hash: string; folder: string; count: number; created_at: string }[]);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [drawerIdx, setDrawerIdx] = useState(null as number | null);
  const [candidates, setCandidates] = useState([] as { path: string; filename: string; src?: string | null }[]);
  const [candLoading, setCandLoading] = useState(false);
  const [showingAllCandidates, setShowingAllCandidates] = useState(false);
  const [pageVersion, setPageVersion] = useState(0);
  const [coverTitle, setCoverTitle] = useState('');
  const [coverPath, setCoverPath] = useState<string | null>(null);
  const [coverWebSrc, setCoverWebSrc] = useState<string | null>(null);
  const [coverPhotoPath, setCoverPhotoPath] = useState<string | null>(null);
  const [coverSubtitle, setCoverSubtitle] = useState('');
  const [coverDateText, setCoverDateText] = useState('');
  const [coverShowDate, setCoverShowDate] = useState(false);
  const [isBuilding, setIsBuilding] = useState(false);
  const [buildProgress, setBuildProgress] = useState(0);
  const [buildMessage, setBuildMessage] = useState('');
  const [latestPdfUrl, setLatestPdfUrl] = useState<string | null>(null);
  const coverItemRef = useRef<any>(null);
  const buildCoverPersistencePayload = (imageRel: string | null, opts?: { item?: any }) => {
    if (!imageRel) return null;
    const normalizedTitle = (coverTitle || '').trim();
    const normalizedSubtitle = (coverSubtitle || '').trim();
    const normalizedDate = (coverDateText || '').trim();
    const showDateFlag = coverShowDate && normalizedDate !== '';
    const payload: Record<string, any> = {
      image: imageRel,
      title: normalizedTitle,
      subtitle: normalizedSubtitle,
      date: showDateFlag ? normalizedDate : '',
      show_date: showDateFlag,
      ...(coverPhotoPath ? { source_path: coverPhotoPath } : {}),
    };
    const item = opts?.item ?? coverItemRef.current ?? null;
    if (item && typeof item === 'object') {
      const fit = item.fit === 'contain' ? 'contain' : 'cover';
      payload.fit = fit;
      payload.crop = fit;
      if (typeof item.crop === 'string') payload.crop = item.crop;
      const alignX = Number.isFinite(Number(item.align?.x)) ? Math.max(-1, Math.min(1, Number(item.align.x))) : 0;
      const alignY = Number.isFinite(Number(item.align?.y)) ? Math.max(-1, Math.min(1, Number(item.align.y))) : 0;
      payload.align = { x: alignX, y: alignY };
      const offX = Number.isFinite(Number(item.offset?.x)) ? Number(item.offset.x) : 0;
      const offY = Number.isFinite(Number(item.offset?.y)) ? Number(item.offset.y) : 0;
      payload.offset = { x: offX, y: offY };
      if (Number.isFinite(Number(item.zoom)) && Number(item.zoom) > 0) payload.zoom = Number(item.zoom);
      if (Number.isFinite(Number(item.rotation))) payload.rotation = Number(item.rotation);
      if (typeof item.auto === 'boolean') payload.auto = item.auto;
      const legacyScale = Number.isFinite(Number(item.scale)) && Number(item.scale) > 0 ? Number(item.scale) : undefined;
      if (legacyScale !== undefined) payload.scale = legacyScale;
      if (Number.isFinite(Number(item.rotate))) payload.rotate = Number(item.rotate);
      const objectPosition = typeof item.objectPosition === 'string' && item.objectPosition.trim() !== ''
        ? item.objectPosition.trim()
        : `${Math.round(50 + alignX * 50)}% ${Math.round(50 + alignY * 50)}%`;
      payload.object_position = objectPosition;
      if (!payload.source_path && item.photo?.path) {
        payload.source_path = item.photo.path;
      }
    }
    return payload;
  };
  const progressTimer = useRef<number | null>(null);
  const feedbackTimer = useRef<number | null>(null);
  const [feedbackAction, setFeedbackAction] = useState<(typeof FEEDBACK_ACTIONS)[number]['value']>(DEFAULT_FEEDBACK_ACTION);
  const [feedbackReason, setFeedbackReason] = useState('');
  const [feedbackSending, setFeedbackSending] = useState(false);
  const [feedbackSuccess, setFeedbackSuccess] = useState<string | null>(null);
  const [feedbackError, setFeedbackError] = useState<string | null>(null);
  const [settingsOpen, setSettingsOpen] = useState(false);
  const clearFeedbackTimer = () => {
    if (feedbackTimer.current) {
      window.clearTimeout(feedbackTimer.current);
      feedbackTimer.current = null;
    }
  };
  const templatesQ = useTemplates();
  useEffect(() => { api.getAlbums().then(r => setAlbums(r?.albums || [])).catch(() => { }); }, []);
  useEffect(() => {
    if (albums.length === 0) return;
    const has = albums.some(a => (a.folder || a.hash) === folder);
    if (!folder || !has) {
      const first = albums[0];
      if (first) setFolder(first.folder || first.hash);
    }
  }, [albums]);
  useEffect(() => () => { clearFeedbackTimer(); }, []);
  const { pagesQ: q, pages } = usePages(folder);
  // Build a web URL for cached assets from an absolute path like .../_cache/<hash>/<rel>
  const filePathToAssetUrl = (p?: string | null): string | null => {
    if (!p) return null;
    const norm = String(p).replace(/^[a-z]+:\/\//i, '').replace(/\\/g, '/');
    const m = norm.match(/\/_cache\/([^/]+)\/(.+)$/);
    if (!m) return null;
    const hash = m[1];
    const rel = m[2];
    // Encode each segment but keep slashes in place
    const encRel = rel.split('/').map(encodeURIComponent).join('/');
    return `/photobook/asset/${encodeURIComponent(hash)}/${encRel}`;
  };
  // Parse relative cache path from a /photobook/asset/{hash}/{rel} URL
  const webAssetRelFromUrl = (u?: string | null): string | null => {
    if (!u) return null;
    const m = String(u).match(/\/photobook\/asset\/[^/]+\/(.+)$/);
    return m ? decodeURIComponent(m[1]) : null;
  };
  // Build a web URL for relative cache asset like images/foo.jpg using album hash
  const relAssetUrl = (hash: string, rel?: string | null): string | null => {
    if (!hash || !rel) return null;
    const encRel = String(rel).split('/').map(encodeURIComponent).join('/');
    return `/photobook/asset/${encodeURIComponent(hash)}/${encRel}`;
  };
  // Hydrate cover from REST if present
  useEffect(() => {
    const data: any = (q as any)?.data;
    const cov = data?.cover;
    const opts = (data?.options ?? {}) as Record<string, any>;
    const pickString = (...vals: any[]) => {
      for (const v of vals) {
        if (typeof v === 'string') {
          const trimmed = v.trim();
          if (trimmed.length) return trimmed;
        }
      }
      return '';
    };
    const pickBool = (...vals: any[]): boolean | null => {
      for (const v of vals) {
        if (typeof v === 'boolean') return v;
        if (v === 0 || v === 1) return !!v;
      }
      return null;
    };

    const subtitleVal = pickString(cov?.cover_subtitle, cov?.subtitle, opts.cover_subtitle, opts.subtitle);
    const rawDate = pickString(cov?.cover_date, cov?.date, opts.cover_date, opts.date);
    const showDatePref = pickBool(cov?.cover_show_date, cov?.show_date, opts.cover_show_date, opts.show_date);
    const defaultShowDate = showDatePref ?? (rawDate !== '');
    const fallbackDateText = defaultShowDate ? defaultCoverDateText() : '';
    const computedDateText = rawDate || fallbackDateText;
    setCoverSubtitle(subtitleVal);
    setCoverDateText(computedDateText);
    setCoverShowDate(defaultShowDate);

    const currentAlbum = albums.find(a => (a.folder || a.hash) === folder) || null;
    const albumHashLocal = currentAlbum?.hash || '';
    const fallbackTitle = pickString(cov?.title, opts.cover_title, opts.title);

    const sourcePath = typeof cov?.sourcePath === 'string' && cov.sourcePath.trim() !== '' ? cov.sourcePath.trim() : null;
    let resolvedSource = sourcePath;
    if (!resolvedSource && Array.isArray(data?.pages)) {
      const coverPage = data.pages.find((p: any) => {
        const id = (p?.id || '').toString().toLowerCase();
        const tpl = (p?.templateId || p?.template || '').toString().toLowerCase();
        return p?.n === 0 || id === 'cover' || tpl === 'cover';
      });
      const coverItem = coverPage?.items?.[0];
      if (coverItem?.photo?.path && typeof coverItem.photo.path === 'string') {
        resolvedSource = coverItem.photo.path;
      }
    }
    const resolvedFilename = resolvedSource ? ((resolvedSource.split('/') || []).pop() || undefined) : undefined;

    const parseAlign = (candidate: any, fallbackPos?: string): { x: number; y: number } => {
      if (candidate && typeof candidate === 'object' && Number.isFinite(Number(candidate.x)) && Number.isFinite(Number(candidate.y))) {
        return {
          x: Math.max(-1, Math.min(1, Number(candidate.x))),
          y: Math.max(-1, Math.min(1, Number(candidate.y))),
        };
      }
      if (typeof fallbackPos === 'string') {
        const parts = fallbackPos.trim().split(/\s+/, 2);
        const px = Number((parts[0] || '').replace('%', ''));
        const py = Number((parts[1] || '').replace('%', ''));
        if (Number.isFinite(px) && Number.isFinite(py)) {
          return {
            x: Math.max(-1, Math.min(1, (px - 50) / 50)),
            y: Math.max(-1, Math.min(1, (py - 50) / 50)),
          };
        }
      }
      return { x: 0, y: 0 };
    };

    if (cov && (cov.image || cov.title)) {
      setCoverTitle(cov.title || fallbackTitle || '');
      setCoverPath(cov.image || null);
      setCoverWebSrc(cov.webSrc || relAssetUrl(albumHashLocal, cov.image) || null);
      setCoverPhotoPath(resolvedSource || null);

      const align = parseAlign(cov.align, cov.objectPosition);
      const offset = (cov.offset && typeof cov.offset === 'object') ? {
        x: Number.isFinite(Number(cov.offset.x)) ? Number(cov.offset.x) : 0,
        y: Number.isFinite(Number(cov.offset.y)) ? Number(cov.offset.y) : 0,
      } : { x: 0, y: 0 };
      const zoomVal = Number.isFinite(Number(cov.zoom)) && Number(cov.zoom) > 0
        ? Number(cov.zoom)
        : (Number.isFinite(Number(cov.scale)) && Number(cov.scale) > 0 ? Number(cov.scale) : 1);
      const rotationVal = Number.isFinite(Number(cov.rotation))
        ? Number(cov.rotation)
        : (Number.isFinite(Number(cov.rotate)) ? Number(cov.rotate) : 0);
      const coverFit = (cov.fit === 'contain' || cov.crop === 'contain') ? 'contain' : 'cover';
      const objectPosition = typeof cov.objectPosition === 'string' && cov.objectPosition.trim() !== ''
        ? cov.objectPosition.trim()
        : `${Math.round(50 + align.x * 50)}% ${Math.round(50 + align.y * 50)}%`;
      coverItemRef.current = {
        slotIndex: 0,
        fit: coverFit,
        align,
        offset,
        zoom: zoomVal,
        rotation: rotationVal,
        auto: cov.auto === true,
        crop: coverFit,
        scale: Number.isFinite(Number(cov.scale)) && Number(cov.scale) > 0 ? Number(cov.scale) : zoomVal,
        rotate: Number.isFinite(Number(cov.rotate)) ? Number(cov.rotate) : rotationVal,
        objectPosition,
        photo: resolvedSource ? {
          path: resolvedSource,
          ...(resolvedFilename ? { filename: resolvedFilename } : {}),
        } : null,
        src: cov.webSrc || relAssetUrl(albumHashLocal, cov.image) || null,
      };
    } else {
      setCoverTitle(fallbackTitle || '');
      setCoverPath(null);
      setCoverWebSrc(null);
      setCoverPhotoPath(null);
      coverItemRef.current = null;
    }
  }, [albums, folder, (q as any)?.data]);
  const displayPages = useMemo(() => {
    const qPages: any[] = ((q as any)?.data?.pages || []) as any[];
    const arr: any[] = (Array.isArray(pages) && pages.length ? pages : qPages) as any[];
    const data: any = (q as any)?.data;
    const cov = data?.cover;
  const topRatio = 0.649;
  const synthesizedSrc = coverWebSrc || cov?.webSrc || null;
  const originalPath = coverPhotoPath || cov?.sourcePath || null;
  const originalFilename = originalPath ? (originalPath.split('/') || []).pop() : undefined;
    const isCoverPage = (p: any) => {
      if (!p) return false;
      const id = (p.id || '').toString().toLowerCase();
      const tpl = (p.templateId || p.template || '').toString().toLowerCase();
      return p.n === 0 || id === 'cover' || tpl === 'cover';
    };
    // If album already contains a real page 0 cover, use it as-is
    if (Array.isArray(arr) && arr.length && isCoverPage(arr[0])) {
      const existing = arr[0] || {};
      const clonedItems = Array.isArray(existing.items) ? existing.items.map((it: any, idx: number) => {
        if (idx === 0) {
          const next: any = { ...it };
          if (synthesizedSrc) next.src = synthesizedSrc;
          if (originalPath) {
            next.photo = {
              ...(next.photo || {}),
              path: originalPath,
              ...(originalFilename ? { filename: originalFilename } : {}),
            };
          }
          return next;
        }
        return { ...it };
      }) : [];
      const coverSlots = [{ x: 0, y: 0, w: 1, h: topRatio }];
      const first = {
        ...existing,
        slots: coverSlots,
        items: clonedItems
      };
      const rest = arr.slice(1).filter((p: any) => !isCoverPage(p));
      return [first, ...rest];
    }
    // Otherwise, synthesize a page 0 cover from top-level cover info
    const coverSlots = [{ x: 0, y: 0, w: 1, h: topRatio }];
    const coverPg: any = {
      id: 'cover', n: 0, templateId: 'cover',
      slots: coverSlots,
      items: [{
        slotIndex: 0,
        src: synthesizedSrc || undefined,
        photo: originalPath ? { path: originalPath, ...(originalFilename ? { filename: originalFilename } : {}) } : null,
        // legacy
        objectPosition: cov?.objectPosition || '50% 50%',
        crop: 'cover',
        scale: (typeof cov?.zoom === 'number' && cov?.zoom > 0) ? cov?.zoom : (cov?.scale || 1),
        rotate: (Number.isFinite(cov?.rotation) ? Number(cov?.rotation) : (cov?.rotate || 0)),
        // canonical
        align: cov?.align || undefined,
        offset: cov?.offset || { x: 0, y: 0 },
        zoom: (typeof cov?.zoom === 'number' && cov?.zoom > 0) ? Number(cov?.zoom) : (cov?.scale || 1),
        rotation: Number.isFinite(cov?.rotation) ? Number(cov?.rotation) : (cov?.rotate || 0),
        auto: cov?.auto === true
      }]
    };
    const rest = Array.isArray(arr) ? arr.filter((p: any) => !isCoverPage(p)) : [];
    return [coverPg, ...rest];
  }, [pages, (q as any)?.data, coverWebSrc, coverPath, coverPhotoPath]);

  const page = useMemo(() => {
    if (!displayPages.length) return null;
    return displayPages[Math.max(0, Math.min(pageIdx, displayPages.length - 1))] as any;
  }, [displayPages, pageIdx]) as any;

  const coverMetaForEditor = useMemo(() => {
    const title = (coverTitle || '').trim();
    const subtitle = (coverSubtitle || '').trim();
    const rawDate = (coverDateText || '').trim();
    const date = coverShowDate && rawDate ? rawDate : null;
    return {
      title: title ? title : null,
      subtitle: subtitle ? subtitle : null,
      date: date ? date : null,
      hasPhoto: !!(coverWebSrc || coverPath || coverPhotoPath),
    };
  }, [coverTitle, coverSubtitle, coverDateText, coverShowDate, coverWebSrc, coverPath, coverPhotoPath]);

  useEffect(() => {
    clearFeedbackTimer();
    setFeedbackAction(DEFAULT_FEEDBACK_ACTION);
    setFeedbackReason('');
    setFeedbackSuccess(null);
    setFeedbackError(null);
    setFeedbackSending(false);
  }, [pageIdx, page?.n]);

  const currentAlbum = useMemo(() => albums.find(a => (a.folder || a.hash) === folder) || null, [albums, folder]);
  const albumHash = currentAlbum?.hash || '';
  const canSubmitFeedback = pageIdx > 0 && !!page;
  const handleFeedbackSubmit = async (event?: React.FormEvent<HTMLFormElement>) => {
    if (event) event.preventDefault();
    if (!canSubmitFeedback || !page) return;

    const action = feedbackAction || DEFAULT_FEEDBACK_ACTION;
    const pageNumber = Number(page?.n ?? 0);
    if (!Number.isFinite(pageNumber) || pageNumber <= 0) {
      clearFeedbackTimer();
      setFeedbackError('Feedback requires a valid interior page');
      setFeedbackSuccess(null);
      return;
    }

    const reason = feedbackReason.trim();

    setFeedbackSending(true);
    try {
      const result = await api.submitFeedback({
        folder,
        page: pageNumber,
        action,
        ...(reason ? { reason } : {}),
      });
      if (!result?.ok) {
        throw new Error('Feedback was not accepted');
      }
      clearFeedbackTimer();
      setFeedbackSuccess('Feedback sent');
      setFeedbackError(null);
      setFeedbackReason('');
      feedbackTimer.current = window.setTimeout(() => {
        setFeedbackSuccess(null);
        feedbackTimer.current = null;
      }, 3000);
    } catch (err) {
      clearFeedbackTimer();
      const message = err instanceof Error ? err.message : 'Failed to send feedback';
      setFeedbackError(message || 'Failed to send feedback');
      setFeedbackSuccess(null);
    } finally {
      setFeedbackSending(false);
    }
  };
  const isLoading = q.isLoading;
  const isError = q.isError;
  const hasPage = !!page;
  const canEditPage = hasPage && !isLoading && !isError;
  const pageLabel = pageIdx === 0 ? 'Cover' : (page ? `Page ${page.n}` : `Page ${pageIdx + 1}`)

  const updateItemObjectPos = (idx: number, xPct: number, yPct: number) => {
    if (!page) return;
    const clampPct = (v: number) => Math.max(0, Math.min(100, Math.round(v)));
    const px = clampPct(xPct);
    const py = clampPct(yPct);
    const ax = (px - 50) / 50; // -> [-1..1]
    const ay = (py - 50) / 50;

    const prev = page.items[idx];
    page.items[idx] = {
      ...prev,
      // legacy mirror
      objectPosition: `${px}% ${py}%`,
      // canonical (what PHP prefers)
      align: { x: ax, y: ay },
      auto: false,
    };
    setPageVersion(v => v + 1);
  };

  const swapItems = (a: number, b: number) => {
    if (!page) return;
    const arr = [...page.items];
    // swap array positions
    [arr[a], arr[b]] = [arr[b], arr[a]];
    // reassign slotIndex to match new order (item i goes to slot i)
    page.items = arr.map((it, i) => ({ ...it, slotIndex: i }));
    setPageVersion(v => v + 1);
  };

  const clamp01 = (v: number, lo = -1, hi = 1) => Math.max(lo, Math.min(hi, v));
  const isPos = (v: any) => Number.isFinite(v) && v > 0;

  const save = async () => {
    if (!page) return;

    await api.savePage({
      folder,
      page: page.n,
      items: page.items.map((it: any) => {
        // canonical (preferred by PHP builder)
        const fit = it.fit === 'contain' ? 'contain' : 'cover';
        const align = {
          x: clamp01(Number(it.align?.x ?? 0)),
          y: clamp01(Number(it.align?.y ?? 0)),
        };
        const offset = {
          x: Number.isFinite(Number(it.offset?.x)) ? Number(it.offset?.x) : 0,
          y: Number.isFinite(Number(it.offset?.y)) ? Number(it.offset?.y) : 0,
        };
        const zoom = isPos(it.zoom) ? Number(it.zoom) : (isPos(it.scale) ? Number(it.scale) : 1);
        const rotation = Number.isFinite(Number(it.rotation))
          ? Number(it.rotation)
          : (Number.isFinite(Number(it.rotate)) ? Number(it.rotate) : 0);
        const auto = !!it.auto;
        const caption =
          typeof it.caption === 'string'
            ? it.caption
            : typeof it.caption === 'number'
              ? String(it.caption)
              : undefined;

        // legacy (keep for back-compat)
        const objectPosition = `${Math.round(50 + align.x * 50)}% ${Math.round(50 + align.y * 50)}%`;

        return {
          slotIndex: it.slotIndex,

          // --- canonical ---
          fit, align, offset, zoom, rotation, auto,
          ...(it.photo?.path ? { photo: { path: it.photo.path, ...(it.photo.filename ? { filename: it.photo.filename } : {}) } } : {}),
          ...(it.src ? { src: it.src } : {}),
          ...(caption !== undefined ? { caption } : {}),

          // --- legacy ---
          crop: fit,
          objectPosition,
          scale: zoom,
          rotate: rotation,
        };
      }),
      templateId: page.templateId || null,
    });

    alert('Saved page overrides');
  };

  // open replace drawer
  const openReplace = async (i: number) => {
    setDrawerIdx(i);
    setDrawerOpen(true);
    setShowingAllCandidates(false);
    setCandLoading(true);
    try {
      // For cover (index 0), use page 1 candidates
      const effectivePage = pageIdx === 0 ? 1 : (page?.n || 1);
      const r = await api.getCandidates(folder, effectivePage);
      setCandidates(r.candidates || []);
    } finally {
      setCandLoading(false);
    }
  };

  // load all candidates from folder
  const loadAllCandidates = async () => {
    setCandLoading(true);
    try {
      const effectivePage = pageIdx === 0 ? 1 : (page?.n || 1);
      const r = await api.getCandidates(folder, effectivePage, true);
      setCandidates(r.candidates || []);
      setShowingAllCandidates(true);
    } finally {
      setCandLoading(false);
    }
  };

  const applyReplacement = (
    cand: { path: string; filename: string; src?: string | null },
    opts?: { preserveCrop?: boolean }
  ) => {
    if (drawerIdx === null) return;

    // Cover: only update the synthetic cover state
    if (pageIdx === 0) {
      const web = cand.src || null;
      const rel = webAssetRelFromUrl(web);
      if (rel) {
        setCoverPath(rel);
      }
      setCoverWebSrc(web);
      setCoverPhotoPath(cand.path || null);
      const prevItem = coverItemRef.current && typeof coverItemRef.current === 'object' ? coverItemRef.current : {};
      const nextPhoto = cand.path ? {
        path: cand.path,
        ...(cand.filename ? { filename: cand.filename } : {}),
      } : (prevItem.photo ?? null);
      coverItemRef.current = {
        slotIndex: 0,
        fit: prevItem.fit === 'contain' ? 'contain' : 'cover',
        align: prevItem.align ?? { x: 0, y: 0 },
        offset: prevItem.offset ?? { x: 0, y: 0 },
        zoom: Number.isFinite(Number(prevItem.zoom)) && Number(prevItem.zoom) > 0 ? Number(prevItem.zoom) : 1,
        rotation: Number.isFinite(Number(prevItem.rotation)) ? Number(prevItem.rotation) : 0,
        auto: prevItem.auto === true,
        crop: typeof prevItem.crop === 'string' ? prevItem.crop : (prevItem.fit === 'contain' ? 'contain' : 'cover'),
        scale: Number.isFinite(Number(prevItem.scale)) && Number(prevItem.scale) > 0 ? Number(prevItem.scale)
          : (Number.isFinite(Number(prevItem.zoom)) && Number(prevItem.zoom) > 0 ? Number(prevItem.zoom) : 1),
        rotate: Number.isFinite(Number(prevItem.rotate)) ? Number(prevItem.rotate)
          : (Number.isFinite(Number(prevItem.rotation)) ? Number(prevItem.rotation) : 0),
        objectPosition: typeof prevItem.objectPosition === 'string' ? prevItem.objectPosition : '50% 50%',
        photo: nextPhoto,
        src: web,
      };
      setDrawerOpen(false);
      setPageVersion(v => v + 1);
      return;
    }

    if (!page) return;
    const it = page.items[drawerIdx];
    const derived = cand.src || filePathToAssetUrl(cand.path) || it.src || null;

    const preserve = !!opts?.preserveCrop;

    page.items[drawerIdx] = {
      ...it,
      photo: { ...(it.photo || {} as any), path: cand.path, filename: cand.filename },
      src: derived || undefined,

      // legacy
      objectPosition: preserve ? it.objectPosition : '50% 50%',
      scale: preserve ? (it.scale ?? 1) : 1,

      // canonical (important!)
      fit: preserve ? (it.fit || 'cover') : 'cover',
      align: preserve ? (it.align || { x: 0, y: 0 }) : { x: 0, y: 0 },
      offset: preserve ? (it.offset || { x: 0, y: 0 }) : { x: 0, y: 0 },
      zoom: preserve ? (Number(it.zoom) || 1) : 1,
      rotation: preserve ? (Number(it.rotation) || 0) : 0,
      auto: false,
    };

    try {
      (page.items[drawerIdx] as any).web = derived || undefined;
      (page.items[drawerIdx] as any).webSrc = derived || undefined;
    } catch { }

    setDrawerOpen(false);
    setPageVersion(v => v + 1);
  };
  return (
    <div className="h-screen flex">
      <main className="flex-1 p-4 flex flex-col gap-3">
        <header className="flex items-center gap-3">
          <input className="border border-neutral-300 rounded px-2 py-1" value={folder} onChange={e => setFolder(e.target.value)} placeholder="Folder" />
          <button className="px-3 py-1 bg-neutral-800 text-white rounded" onClick={() => q.refetch()}>Load</button>
          <select aria-label="Albums" className="border border-neutral-300 rounded px-2 py-1" value={folder} onChange={e => { setFolder(e.target.value); setPageIdx(0); }}>
            <option value="">Select album…</option>
            {albums.map(a => (
              <option key={a.hash} value={a.folder || a.hash}>{(a.folder || a.hash)} ({a.count})</option>
            ))}
          </select>
          <div className="flex items-center gap-2 ml-6">
            <button disabled={pageIdx <= 0} className="px-3 py-1 rounded bg-neutral-200 disabled:opacity-50" onClick={() => setPageIdx(p => Math.max(0, p - 1))}>Prev</button>
            <div className="text-sm">{pageLabel}</div>
            <button disabled={displayPages.length <= pageIdx + 1} className="px-3 py-1 rounded bg-neutral-200 disabled:opacity-50" onClick={() => setPageIdx(p => p + 1)}>Next</button>
          </div>
          <form className="ml-auto flex items-center gap-2 border-l border-neutral-200 pl-3" onSubmit={handleFeedbackSubmit}>
            <span className="text-sm text-neutral-600">Feedback</span>
            <select
              aria-label="Feedback action"
              className="border border-neutral-300 rounded px-2 py-1 text-sm disabled:opacity-50"
              value={feedbackAction}
              onChange={e => setFeedbackAction(e.target.value as (typeof FEEDBACK_ACTIONS)[number]['value'])}
              disabled={!canSubmitFeedback || feedbackSending}
            >
              {FEEDBACK_ACTIONS.map(opt => (
                <option key={opt.value} value={opt.value}>{opt.label}</option>
              ))}
            </select>
            <input
              className="border border-neutral-300 rounded px-2 py-1 text-sm w-48 disabled:opacity-50"
              type="text"
              placeholder="Optional note"
              value={feedbackReason}
              onChange={e => setFeedbackReason(e.target.value)}
              disabled={!canSubmitFeedback || feedbackSending}
            />
            <button
              type="submit"
              className="px-3 py-1 rounded bg-neutral-200 text-sm disabled:opacity-50"
              disabled={!canSubmitFeedback || feedbackSending}
            >
              {feedbackSending ? 'Sending…' : 'Send'}
            </button>
            {feedbackSuccess && <span className="text-xs text-green-600">{feedbackSuccess}</span>}
            {feedbackError && !feedbackSuccess && <span className="text-xs text-red-600">{feedbackError}</span>}
          </form>
          <button className="px-3 py-1 rounded bg-blue-600 text-white ml-3" onClick={async () => {
            if (pageIdx === 0) {
              const hasAlbumHash = !!(albumHash && /^[a-f0-9]{40}$/i.test(albumHash));
              if (!hasAlbumHash) {
                alert('Cover can only be saved once the album has been initialized (build at least once).');
                return;
              }
              const coverImageRel = coverPath || webAssetRelFromUrl(coverWebSrc) || null;
              if (!coverImageRel) {
                alert('Choose a cover photo before saving.');
                return;
              }
              const itemForPayload = coverItemRef.current ?? (page?.items?.[0] ?? null);
              const coverPayload = buildCoverPersistencePayload(coverImageRel, { item: itemForPayload });
              if (!coverPayload) {
                alert('Unable to build cover payload for saving.');
                return;
              }
              try {
                await PB.setCover(albumHash, coverPayload);
                alert('Saved cover');
              } catch (err) {
                console.warn('Failed to persist cover metadata', err);
                alert('Failed to save cover');
              }
              return;
            }
            await save();
          }} disabled={!canEditPage}>{pageIdx === 0 ? 'Save cover' : 'Save'}</button>
          <button
            className="px-3 py-1 rounded bg-neutral-100 text-neutral-700 border border-neutral-300 hover:bg-neutral-200"
            onClick={() => setSettingsOpen(true)}
            title="Settings"
          >
            ⚙️ Settings
          </button>
          <button
            className="px-3 py-1 rounded bg-green-600 text-white disabled:opacity-60"
            disabled={!folder || isBuilding}
            onClick={async () => {
              if (!folder) {
                alert('Select a folder to build');
                return;
              }
              const hasAlbumHash = !!(albumHash && /^[a-f0-9]{40}$/i.test(albumHash));
              const coverImageRelForApi = coverPath || webAssetRelFromUrl(coverWebSrc) || null;
              const coverPayloadForApi = buildCoverPersistencePayload(coverImageRelForApi, { item: coverItemRef.current });
              try {
                // Persist cover via REST if available and albumHash exists
                if (hasAlbumHash && coverPayloadForApi) {
                  try { await PB.setCover(albumHash, coverPayloadForApi); } catch { }
                }

                setIsBuilding(true); setBuildProgress(0); setBuildMessage('Starting build...');

                // Build payload
                const payload: Record<string, string> = { folder };
                if (coverTitle) payload.title = coverTitle;
                if (coverImageRelForApi) payload.cover_image = coverImageRelForApi;
                const trimmedSubtitle = (coverSubtitle || '').trim();
                const trimmedDate = (coverDateText || '').trim();
                if (trimmedSubtitle !== '') payload.cover_subtitle = trimmedSubtitle;
                if (trimmedDate !== '') payload.cover_date = trimmedDate;
                payload.cover_show_date = coverShowDate ? '1' : '0';

                // Start build and determine which hash to poll
                let buildHash = albumHash;
                if (hasAlbumHash) {
                  await PB.build(albumHash, payload);
                } else {
                  const r = await PB.buildByFolder(payload);
                  buildHash = r?.hash || '';
                }

                setBuildMessage('Build started successfully');
                if (progressTimer.current) { window.clearInterval(progressTimer.current); progressTimer.current = null; }
                const pollHash = buildHash; // capture for closure
                progressTimer.current = window.setInterval(async () => {
                  try {
                    const r: any = await PB.progress(pollHash);
                    const p = r?.status?.progress ?? 0;
                    const msg = r?.status?.step || r?.status?.message || r?.status?.state || '';
                    setBuildProgress(p);
                    setBuildMessage(msg);
                      if (p >= 100) {
                      if (progressTimer.current) { window.clearInterval(progressTimer.current); progressTimer.current = null; }
                      setIsBuilding(false);
                      setBuildMessage('Build complete!');
                        try {
                          // Ask backend for latest PDF URL
                          const lr = await fetch('/photobook/latest-pdf.json', { credentials: 'same-origin' });
                          if (lr.ok) {
                            const j = await lr.json();
                            const url = j?.ok && j?.url ? String(j.url) : null;
                            if (url) {
                              setLatestPdfUrl(url);
                              // Attempt to open in a new tab as well (some browsers may block if not user-initiated)
                              try { window.open(url, '_blank', 'noopener'); } catch {}
                            }
                          }
                        } catch {}
                        setTimeout(() => setBuildMessage(''), 2000);
                      q.refetch();
                    }
                  } catch (e) {
                    if (progressTimer.current) { window.clearInterval(progressTimer.current); progressTimer.current = null; }
                    setIsBuilding(false);
                    setBuildMessage('Build completed (progress unavailable)');
                    try {
                      const lr = await fetch('/photobook/latest-pdf.json', { credentials: 'same-origin' });
                      if (lr.ok) {
                        const j = await lr.json();
                        const url = j?.ok && j?.url ? String(j.url) : null;
                        if (url) {
                          setLatestPdfUrl(url);
                          try { window.open(url, '_blank', 'noopener'); } catch {}
                        }
                      }
                    } catch {}
                    setTimeout(() => setBuildMessage(''), 2000);
                  }
                }, 1000);

              } catch (e) {
                if (progressTimer.current) { window.clearInterval(progressTimer.current); progressTimer.current = null; }
                setIsBuilding(false);
                setBuildMessage('Build failed to start');
                setTimeout(() => setBuildMessage(''), 2000);
              }
            }}
          >{isBuilding ? `Building… ${Math.round(buildProgress)}%` : 'Build'}</button>
        </header>
        {isBuilding && (
          <div className="-mt-2 mb-2 flex items-center gap-3 text-sm text-neutral-700">
            <div className="w-64 h-2 bg-neutral-200 rounded overflow-hidden">
              <div className="h-full bg-green-600" style={{ width: `${Math.max(0, Math.min(100, buildProgress))}%` }} />
            </div>
            <span>{buildMessage}</span>
          </div>
        )}
        {albumHash && pageIdx === 0 && (
          <div className="mt-2 flex flex-col gap-2">
            <div className="flex items-center gap-2">
              <label className="text-sm w-24">Title</label>
              <input className="border border-neutral-300 rounded px-2 py-1 flex-1 min-w-[220px]" value={coverTitle} onChange={e => setCoverTitle(e.target.value)} placeholder="Cover title" />
              <button className="px-3 py-1 rounded bg-neutral-200 disabled:opacity-50" disabled={!canEditPage} onClick={() => openReplace(0)}>Choose cover photo…</button>
              {coverWebSrc ? <img src={coverWebSrc} alt="cover" className="h-10 rounded border" /> : <span className="text-xs text-neutral-500">No image</span>}
            </div>
            <div className="flex items-center gap-2">
              <label className="text-sm w-24">Subheadline</label>
              <input
                className="border border-neutral-300 rounded px-2 py-1 flex-1 min-w-[220px]"
                value={coverSubtitle}
                onChange={e => setCoverSubtitle(e.target.value)}
                placeholder="Subheadline (optional)"
              />
            </div>
            <div className="flex items-center gap-3">
              <label className="text-sm w-24">Date</label>
              <label className="flex items-center gap-2 text-sm text-neutral-700">
                <input
                  type="checkbox"
                  className="accent-neutral-800"
                  checked={coverShowDate}
                  onChange={(e) => {
                    const next = e.target.checked;
                    setCoverShowDate(next);
                    if (next && !(coverDateText || '').trim()) {
                      setCoverDateText(defaultCoverDateText());
                    }
                  }}
                />
                <span>Show</span>
              </label>
              <input
                className="border border-neutral-300 rounded px-2 py-1 flex-1 min-w-[220px] disabled:bg-neutral-100 disabled:text-neutral-500"
                value={coverDateText}
                onChange={e => setCoverDateText(e.target.value)}
                placeholder="e.g. Summer 2025"
                disabled={!coverShowDate}
              />
            </div>
          </div>
        )}

        <div className="flex-1 flex gap-4 overflow-hidden">
          <div className="flex-1 flex items-center justify-center overflow-auto">
            {isLoading ? (
              <div className="text-sm text-neutral-500">Loading pages…</div>
            ) : isError ? (
              <div className="text-sm text-red-600">Failed to load pages.json</div>
            ) : !page ? (
              <div className="text-sm text-neutral-500 text-center">
                {folder ? `No pages.json yet. Folder: ${folder}` : 'Select an album to load pages.'}
              </div>
            ) : (
              <EditorCanvas
                page={page}
                version={pageVersion}
                coverMeta={pageIdx === 0 ? coverMetaForEditor : undefined}
                onChange={(items) => {
                  if (page) {
                    page.items = items as any;
                    setPageVersion(v => v + 1);
                  }
                  if (pageIdx === 0 && Array.isArray(items) && items.length) {
                    const first = items[0] as any;
                    const existing = coverItemRef.current ?? {};
                    let photo = first?.photo ?? existing.photo ?? null;
                    if ((!photo || !photo.path) && coverPhotoPath) {
                      const fname = (coverPhotoPath.split('/') || []).pop() || undefined;
                      photo = { path: coverPhotoPath, ...(fname ? { filename: fname } : {}) };
                    }
                    coverItemRef.current = {
                      ...existing,
                      ...(first ? { ...first } : {}),
                      slotIndex: 0,
                      photo,
                    };
                  }
                }}
                onSave={async (items: any[]) => {
                  if (!page) return;
                  await api.savePage({
                    folder,
                    page: page.n,
                    items: items.map((it: any) => {
                      const fit = it.fit === 'contain' ? 'contain' : 'cover';
                      const align = { x: clamp01(Number(it.align?.x ?? 0)), y: clamp01(Number(it.align?.y ?? 0)) };
                      const offset = { x: Number(it.offset?.x) || 0, y: Number(it.offset?.y) || 0 };
                      const zoom = isPos(it.zoom) ? Number(it.zoom) : 1;
                      const rotation = Number.isFinite(Number(it.rotation)) ? Number(it.rotation) : 0;
                      const objectPosition = `${Math.round(50 + align.x * 50)}% ${Math.round(50 + align.y * 50)}%`;
                      const caption =
                        typeof it.caption === 'string'
                          ? it.caption
                          : typeof it.caption === 'number'
                            ? String(it.caption)
                            : undefined;

                      return {
                        slotIndex: it.slotIndex,

                        // canonical
                        fit, align, offset, zoom, rotation, auto: !!it.auto,
                        ...(it.photo?.path ? { photo: { path: it.photo.path, ...(it.photo.filename ? { filename: it.photo.filename } : {}) } } : {}),
                        ...(it.src ? { src: it.src } : {}),
                        ...(caption !== undefined ? { caption } : {}),

                        // legacy
                        crop: fit,
                        objectPosition,
                        scale: zoom,
                        rotate: rotation,
                      };
                    }),
                    templateId: page.templateId || null,
                  });
                  alert('Saved page overrides');
                }}

              />
            )}
          </div>
          {isLoading ? (
            <aside className="w-72 p-3 bg-white border-l border-neutral-200 flex items-center justify-center text-sm text-neutral-500">
              Loading pages…
            </aside>
          ) : isError ? (
            <aside className="w-72 p-3 bg-white border-l border-neutral-200 flex items-center justify-center text-sm text-red-600 text-center">
              Unable to load pages.json.
            </aside>
          ) : !page ? (
            <aside className="w-72 p-3 bg-white border-l border-neutral-200 flex items-center justify-center text-sm text-neutral-500 text-center">
              {folder ? `No pages found for ${folder}.` : 'No page data yet.'}
            </aside>
          ) : (
            <Sidebar page={page} onSwap={swapItems} onReplace={openReplace} onUpdateItem={(idx, changes) => {
              if (!page) return;
              const existing = page.items?.[idx];
              if (!existing) return;
              page.items[idx] = { ...existing, ...changes };
              setPageVersion(v => v + 1);
            }} onTemplateChange={async (tpl) => {
              if (!page) return;
              if (pageIdx === 0) return; // no template selection for cover
              // Apply slots from selected template immediately in UI
              try {
                const groups = templatesQ.data || {} as any;
                const count = Array.isArray(page.items) ? page.items.length : 0;
                const arr = (groups[String(count)] || groups[count] || []) as any[];
                const match = arr.find((t:any) => t.id === tpl);
                if (match && Array.isArray(match.slots)) {
                  page.templateId = tpl;
                  // Replace slots with the chosen template's geometry
                  page.slots = match.slots.map((s:any)=>({ x: s.x, y: s.y, w: s.w, h: s.h, ...(s.ar?{ ar: s.ar }: {}) }));
                  // Reassign items' slotIndex to sequential indices matching new slots
                  page.items = (page.items || []).map((it:any, i:number) => ({ ...it, slotIndex: i }));
                  setPageVersion(v => v + 1);
                } else {
                  // Fallback: still set templateId so backend override persists
                  page.templateId = tpl;
                  setPageVersion(v => v + 1);
                }
              } catch {}
              await api.overrideTemplate({ folder, page: page.n, templateId: tpl });
              alert('Template set to ' + tpl);
            }} />
          )}
        </div>
        {drawerOpen && !canEditPage && (
          <div className="fixed inset-0 z-50">
            <div className="absolute inset-0 bg-black/40" onClick={() => setDrawerOpen(false)} />
            <div className="absolute right-0 top-0 h-full w-[420px] bg-white shadow-xl border-l border-neutral-200 flex flex-col items-center justify-center gap-3 p-6 text-center">
              <div className="text-sm text-neutral-700">
                {isLoading ? 'Loading pages…' : isError ? 'Unable to load page data.' : 'No page data available.'}
              </div>
              <button className="px-3 py-1 text-sm rounded bg-neutral-200" onClick={() => setDrawerOpen(false)}>Close</button>
            </div>
          </div>
        )}
        <ReplaceDrawer
          open={drawerOpen && canEditPage}
          onClose={() => setDrawerOpen(false)}
          loading={candLoading}
          candidates={candidates}
          onPick={(c, o) => applyReplacement(c, o)}
          onLoadAll={loadAllCandidates}
          showingAll={showingAllCandidates}
        />
      </main>
      {latestPdfUrl && (
        <PdfReadyModal url={latestPdfUrl} onClose={() => setLatestPdfUrl(null)} />
      )}
      <SettingsPanel open={settingsOpen} onClose={() => setSettingsOpen(false)} />
    </div>
  );
}
