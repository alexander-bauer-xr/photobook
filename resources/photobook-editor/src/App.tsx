import React, { useEffect, useMemo, useRef, useState } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { usePhotobook } from './hooks/usePhotobook';
import EditorCanvas from './components/EditorCanvas';
import Sidebar from './components/Sidebar';
import ReplaceDrawer from './components/ReplaceDrawer';
import PdfReadyModal from './components/PdfReadyModal';
import SettingsPanel from './components/SettingsPanel';
import { useTemplates } from './hooks/useTemplates';
import { useAlbumSelection } from './features/photobook/hooks/useAlbumSelection';
import { useBuildPhotobook } from './features/photobook/hooks/useBuildPhotobook';
import { useCoverEditor } from './features/photobook/hooks/useCoverEditor';
import { useDisplayPages } from './features/photobook/hooks/useDisplayPages';
import { usePagePersistence } from './features/photobook/hooks/usePagePersistence';
import { usePdfExport } from './features/photobook/hooks/usePdfExport';
import { useSaveStatus } from './features/photobook/hooks/useSaveStatus';
import {
  filePathToAssetUrl,
  normalizeAssetRel,
  relAssetUrl,
  webAssetRelFromUrl,
} from './features/photobook/model/assetUrls';
import { PB } from './lib/api';
import { Badge } from './components/ui/badge';
import { Button } from './components/ui/button';
import { Card, CardContent } from './components/ui/card';
import { Checkbox } from './components/ui/checkbox';
import { Input } from './components/ui/input';
import { Label } from './components/ui/label';
import { Separator } from './components/ui/separator';
import { usePB } from './store/photobook';

const qc = new QueryClient();

const defaultCoverDateText = () => new Date().toLocaleString('en-US', { month: 'long', year: 'numeric' });
const selectControlClassName =
  'h-10 rounded-xl border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900 shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-neutral-300';

export default function App({ initialAlbumKey = '' }: { initialAlbumKey?: string }) {
  return <QueryClientProvider client={qc}><Root initialAlbumKey={initialAlbumKey} /></QueryClientProvider>;
}

function Root({ initialAlbumKey = '' }: { initialAlbumKey?: string }) {
  const {
    folder,
    setFolder,
    albums,
    refreshAlbums,
    albumHash,
    albumFolder,
    pagesKey,
  } = useAlbumSelection(initialAlbumKey);
  const [pageIdx, setPageIdx] = useState(0);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [drawerIdx, setDrawerIdx] = useState(null as number | null);
  const [candidates, setCandidates] = useState([] as { path: string; filename: string; src?: string | null }[]);
  const [candLoading, setCandLoading] = useState(false);
  const [showingAllCandidates, setShowingAllCandidates] = useState(false);
  const [pageVersion, setPageVersion] = useState(0);
  const { latestPdfUrl, setLatestPdfUrl, isExporting, exportError, handleExportPdf } = usePdfExport(albumHash);
  const { saveStatus, setSaveStatus, persistWithStatus } = useSaveStatus();
  const suppressNextCanvasDirty = useRef(false);
  const updateStoreItem = usePB((s) => s.updateItem);
  const updateStoreItemWith = usePB((s) => s.updateItemWith);
  const replaceStorePageItems = usePB((s) => s.replacePageItems);
  const swapStoreItems = usePB((s) => s.swapItems);
  const updateStoreTemplate = usePB((s) => s.updateTemplate);
  const updateStorePageFeedback = usePB((s) => s.updatePageFeedback);
  const { persistPage } = usePagePersistence({ albumHash, persistWithStatus });
  const [settingsOpen, setSettingsOpen] = useState(false);
  const templatesQ = useTemplates();
  const { pagesQ: q, pages } = usePhotobook(pagesKey);
  const queryData: any = (q as any)?.data;
  const {
    coverTitle,
    setCoverTitle,
    coverPath,
    setCoverPath,
    coverWebSrc,
    setCoverWebSrc,
    coverPhotoPath,
    setCoverPhotoPath,
    coverSubtitle,
    setCoverSubtitle,
    coverDateText,
    setCoverDateText,
    coverShowDate,
    setCoverShowDate,
    coverItemRef,
    buildCoverPersistencePayload,
    coverMetaForEditor,
    currentCoverItem,
    currentCoverImageRel,
  } = useCoverEditor({
    queryData,
    pages,
    folder,
    albumHash,
    setPageVersion,
    suppressNextCanvasDirty,
  });
  useEffect(() => {
    setPageVersion(v => v + 1);
  }, [pagesKey]);
  const displayPages = useDisplayPages({
    queryData,
    pages,
    coverWebSrc,
    coverPath,
    coverPhotoPath,
    coverItemRef,
  });

  const page = useMemo(() => {
    if (!displayPages.length) return null;
    return displayPages[Math.max(0, Math.min(pageIdx, displayPages.length - 1))] as any;
  }, [displayPages, pageIdx]) as any;

  const pageId = page
    ? String(page.id ?? (pageIdx === 0 ? 'cover' : `n-${page.n ?? pageIdx}`))
    : null;

  const isLoading = q.isLoading;
  const isFetchingPages = q.isFetching;
  const isError = q.isError;
  const hasPages = Array.isArray(displayPages) && displayPages.length > 0 && !isError;
  const showInitialLoading = isLoading && !hasPages;
  const hasPage = !!page;
  const canEditPage = hasPage && !isLoading && !isError;
  const pageLabel = pageIdx === 0 ? 'Cover' : (page ? `Page ${page.n}` : `Page ${pageIdx + 1}`)
  const isCoverPage = (target?: any) => {
    if (!target) return false;
    const id = (target.id || '').toString().toLowerCase();
    const tpl = (target.templateId || target.template || '').toString().toLowerCase();
    return id === 'cover' || tpl === 'cover';
  };
  const pageIdentity = (target: any) => {
    if (isCoverPage(target)) return 'cover';
    const n = Math.max(1, Number(target?.n || 1));
    return target?.id || `page-${n}`;
  };
  const persistCover = async (imageRel = currentCoverImageRel(), item = currentCoverItem(), sourcePath?: string | null) => {
    if (!albumHash) throw new Error('Album hash is not ready yet.');
    const coverPayload = buildCoverPersistencePayload(imageRel, { item, sourcePath });
    if (!coverPayload) throw new Error('Choose a cover photo before saving.');
    await persistWithStatus(() => PB.setCover(albumHash, coverPayload));
  };
  const { isBuilding, buildProgress, buildMessage, handleBuild } = useBuildPhotobook({
    folder,
    albumHash,
    albumFolder,
    coverTitle,
    coverSubtitle,
    coverDateText,
    coverShowDate,
    currentCoverImageRel,
    currentCoverItem,
    buildCoverPersistencePayload,
    setFolder,
    refreshAlbums,
    refetchPages: () => {
      void q.refetch();
    },
  });

  const swapItems = (from: number, to: number) => {
    if (!page || !pageId || pageIdx === 0) return;

    const nextItems = [...(page.items || [])];
    const [moved] = nextItems.splice(from, 1);

    if (!moved) return;

    nextItems.splice(to, 0, moved);

    const normalizedItems = nextItems.map((item, index) => ({
      ...item,
      slotIndex: index,
      auto: false,
    }));

    swapStoreItems(pageId, from, to);
    setPageVersion(v => v + 1);

    void persistPage(page, normalizedItems).catch(() => {});
  };

  const save = async () => {
    if (!page) return;
    if (pageIdx === 0) {
      await persistCover();
      return;
    }
    await persistPage(page);
  };

  // open replace drawer
  const openReplace = async (i: number) => {
    if (!albumHash) return;

    setDrawerIdx(i);
    setDrawerOpen(true);
    setShowingAllCandidates(false);
    setCandLoading(true);
    try {
      const effectivePage = pageIdx === 0 ? 1 : (page?.n || 1);
      const r = await PB.getCandidates(albumHash, effectivePage);
      setCandidates(r.candidates || []);
    } finally {
      setCandLoading(false);
    }
  };

  // load all candidates from folder
  const loadAllCandidates = async () => {
    if (!albumHash) return;

    setCandLoading(true);
    try {
      const effectivePage = pageIdx === 0 ? 1 : (page?.n || 1);
      const r = await PB.getCandidates(albumHash, effectivePage, true);
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
      const rel = webAssetRelFromUrl(cand.src || null) || normalizeAssetRel(cand.path);
      const web = cand.src || relAssetUrl(albumHash, rel) || filePathToAssetUrl(cand.path) || null;
      if (rel) setCoverPath(rel);
      setCoverWebSrc(web);
      setCoverPhotoPath(cand.path || null);
      const prevItem = coverItemRef.current && typeof coverItemRef.current === 'object' ? coverItemRef.current : {};
      const preserve = opts?.preserveCrop !== false;
      const nextPhoto = cand.path ? {
        path: cand.path,
        ...(cand.filename ? { filename: cand.filename } : {}),
      } : (prevItem.photo ?? null);
      const nextItem = {
        slotIndex: 0,
        fit: preserve && prevItem.fit === 'contain' ? 'contain' : 'cover',
        align: preserve ? (prevItem.align ?? { x: 0, y: 0 }) : { x: 0, y: 0 },
        offset: preserve ? (prevItem.offset ?? { x: 0, y: 0 }) : { x: 0, y: 0 },
        zoom: preserve && Number.isFinite(Number(prevItem.zoom)) && Number(prevItem.zoom) > 0 ? Number(prevItem.zoom) : 1,
        rotation: preserve && Number.isFinite(Number(prevItem.rotation)) ? Number(prevItem.rotation) : 0,
        auto: false,
        crop: preserve && typeof prevItem.crop === 'string' ? prevItem.crop : (preserve && prevItem.fit === 'contain' ? 'contain' : 'cover'),
        scale: preserve
          ? (Number.isFinite(Number(prevItem.scale)) && Number(prevItem.scale) > 0 ? Number(prevItem.scale)
            : (Number.isFinite(Number(prevItem.zoom)) && Number(prevItem.zoom) > 0 ? Number(prevItem.zoom) : 1))
          : 1,
        rotate: preserve
          ? (Number.isFinite(Number(prevItem.rotate)) ? Number(prevItem.rotate)
            : (Number.isFinite(Number(prevItem.rotation)) ? Number(prevItem.rotation) : 0))
          : 0,
        objectPosition: preserve && typeof prevItem.objectPosition === 'string' ? prevItem.objectPosition : '50% 50%',
        photo: nextPhoto,
        src: web,
      };
      coverItemRef.current = nextItem;
      setDrawerOpen(false);
      setPageVersion(v => v + 1);
      void persistCover(rel, nextItem, cand.path || null).catch(() => { });
      return;
    }

    if (!page || !pageId) return;
    const it = page.items[drawerIdx];
    const derived = cand.src || filePathToAssetUrl(cand.path) || it?.src || null;
    const preserve = !!opts?.preserveCrop;

    const currentItems = Array.isArray(page.items) ? page.items : [];
    const prev = currentItems[drawerIdx];

    if (!prev) return;

    const nextItem = {
      ...prev,
      photo: {
        ...(prev.photo || {}),
        path: cand.path,
        filename: cand.filename,
      },
      src: derived || undefined,
      web: derived || undefined,
      webSrc: derived || undefined,
      objectPosition: preserve ? prev.objectPosition : '50% 50%',
      scale: preserve ? (prev.scale ?? 1) : 1,
      fit: preserve ? (prev.fit || 'cover') : 'cover',
      align: preserve ? (prev.align || { x: 0, y: 0 }) : { x: 0, y: 0 },
      offset: preserve ? (prev.offset || { x: 0, y: 0 }) : { x: 0, y: 0 },
      zoom: preserve ? (Number(prev.zoom) || 1) : 1,
      rotation: preserve ? (Number(prev.rotation) || 0) : 0,
      auto: false,
    };

    const nextItems = currentItems.map((item: any, index: number) =>
      index === drawerIdx ? nextItem : item,
    );

    updateStoreItemWith(pageId, drawerIdx, () => nextItem);

    setDrawerOpen(false);
    setPageVersion(v => v + 1);

    void persistPage(page, nextItems).catch(() => {});
  };

  return (
    <div className="flex h-screen overflow-hidden bg-[radial-gradient(circle_at_top_left,_rgba(191,219,254,0.35),_transparent_28%),linear-gradient(180deg,_#fafaf9_0%,_#f8fafc_100%)]">
      <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
        {/* ── Build Overlay ───────────────────────────────────────────── */}
        {isBuilding && (
          <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center">
            <div className="bg-white rounded-2xl shadow-2xl p-10 flex flex-col items-center gap-6 w-[480px] max-w-[90vw]">
              <div className="text-2xl font-semibold text-neutral-800">Building Photobook…</div>
              <div className="w-full">
                <div className="flex justify-between text-xs text-neutral-500 mb-1">
                  <span>{buildMessage || 'Please wait'}</span>
                  <span>{Math.round(buildProgress)}%</span>
                </div>
                <div className="w-full h-3 bg-neutral-200 rounded-full overflow-hidden">
                  <div
                    className="h-full bg-green-500 rounded-full transition-all duration-500"
                    style={{ width: `${Math.max(2, Math.min(100, buildProgress))}%` }}
                  />
                </div>
              </div>
              <p className="text-sm text-neutral-500 text-center">
                Photos are being downloaded, analysed and arranged into pages.<br />
                This may take a few minutes.
              </p>
            </div>
          </div>
        )}

        {/* ── Export Overlay ──────────────────────────────────────────── */}
        {isExporting && (
          <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center">
            <div className="bg-white rounded-2xl shadow-2xl p-10 flex flex-col items-center gap-6 w-[480px] max-w-[90vw]">
              <div className="text-2xl font-semibold text-neutral-800">Exporting PDF…</div>
              <div className="flex items-center gap-3">
                <div className="h-5 w-5 animate-spin rounded-full border-2 border-neutral-300 border-t-neutral-700" />
                <span className="text-sm text-neutral-500">Rendering pages with Playwright</span>
              </div>
              <p className="text-sm text-neutral-500 text-center">
                All pages are being rendered to a print-ready PDF.<br />
                This may take up to a minute.
              </p>
            </div>
          </div>
        )}

        {/* ── Top bar ─────────────────────────────────────────────────── */}
        <header className="flex-none border-b border-neutral-200/70 bg-white/80 px-4 py-4 backdrop-blur-sm">
          <div className="flex min-w-0 flex-wrap items-center gap-3">
            <div className="mr-3 shrink-0">
              <div className="text-[11px] font-medium uppercase tracking-[0.18em] text-neutral-500">Photobook Editor</div>
              <div className="mt-1 text-lg font-semibold text-neutral-900">Layout workspace</div>
            </div>
            <div className="flex min-w-0 flex-1 flex-wrap items-center gap-3">
              <select
                aria-label="Albums"
                className={`${selectControlClassName} min-w-[12rem] max-w-full flex-1 md:max-w-[240px]`}
                value={folder}
                onChange={e => { setFolder(e.target.value); setPageIdx(0); }}
              >
                <option value="">Select album…</option>
                {albums.map(a => (
                  <option key={a.hash} value={a.folder || a.hash}>{a.folder || a.hash} ({a.count})</option>
                ))}
              </select>
              <Input
                className="min-w-[14rem] flex-[1.2] md:max-w-[22rem]"
                value={folder}
                onChange={e => setFolder(e.target.value)}
                placeholder="or type folder path…"
              />
              <Button
                variant="success"
                disabled={!folder || isBuilding}
                onClick={handleBuild}
                title={hasPages ? 'Rebuild from Nextcloud' : 'Generate pages from Nextcloud folder'}
              >
                {hasPages ? 'Rebuild' : 'Build'}
              </Button>
            </div>

            <div className="ml-auto flex flex-wrap items-center gap-3">
              {isFetchingPages && (
                <Badge>Loading pages…</Badge>
              )}
              {saveStatus === 'dirty' && <Badge variant="warning">Unsaved edits</Badge>}
              {saveStatus === 'saving' && <Badge>Saving…</Badge>}
              {saveStatus === 'saved' && <Badge variant="success">Saved</Badge>}
              {saveStatus === 'error' && <Badge variant="warning">Save failed</Badge>}
              {hasPages && (
                <>
                  <Separator orientation="vertical" className="mx-1 hidden h-8 md:block" />
                  <div className="flex items-center gap-2">
                    <Button
                      size="sm"
                      variant="secondary"
                      disabled={pageIdx <= 0}
                      onClick={() => setPageIdx(p => Math.max(0, p - 1))}
                    >
                      Prev
                    </Button>
                    <Badge>{pageLabel}</Badge>
                    <Button
                      size="sm"
                      variant="secondary"
                      disabled={displayPages.length <= pageIdx + 1}
                      onClick={() => setPageIdx(p => p + 1)}
                    >
                      Next
                    </Button>
                  </div>
                  <Separator orientation="vertical" className="mx-1 hidden h-8 md:block" />
                  <Button
                    variant="brand"
                    disabled={!canEditPage || (pageIdx === 0 && !albumHash)}
                    onClick={async () => {
                      try { await save(); }
                      catch (error) { alert(error instanceof Error ? error.message : 'Failed to save'); }
                    }}
                  >
                    {pageIdx === 0 ? 'Save cover' : 'Save page'}
                  </Button>
                  <Button
                    variant="outline"
                    disabled={isExporting || !albumHash}
                    onClick={handleExportPdf}
                    title="Export to PDF via Playwright"
                  >
                    {isExporting ? 'Exporting…' : 'Export PDF'}
                  </Button>
                  {exportError && <Badge variant="warning">{exportError}</Badge>}
                </>
              )}

              <Button variant="outline" onClick={() => setSettingsOpen(true)} title="Settings">
                Settings
              </Button>
            </div>
          </div>
        </header>

        {/* ── Cover editor bar (only on page 0 after build) ──────────── */}
        {hasPages && albumHash && pageIdx === 0 && (
          <div className="flex-none border-b border-neutral-200/70 bg-white/70 px-4 py-4">
            <Card className="rounded-[26px] border-neutral-200/80 bg-white/90 shadow-sm">
              <CardContent className="grid gap-4 p-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,0.85fr)_auto]">
                <div className="xl:col-span-full">
                  <div className="text-[11px] font-medium uppercase tracking-[0.16em] text-neutral-500">Cover</div>
                </div>
                <div className="min-w-0">
                  <Label htmlFor="cover-title" className="mb-2 block text-xs uppercase tracking-[0.12em] text-neutral-500">
                    Title
                  </Label>
                  <Input
                    id="cover-title"
                    value={coverTitle}
                    onChange={e => { setCoverTitle(e.target.value); setSaveStatus('dirty'); }}
                    placeholder="Cover title"
                  />
                </div>
                <div className="min-w-0">
                  <Label htmlFor="cover-subtitle" className="mb-2 block text-xs uppercase tracking-[0.12em] text-neutral-500">
                    Subheadline
                  </Label>
                  <Input
                    id="cover-subtitle"
                    value={coverSubtitle}
                    onChange={e => { setCoverSubtitle(e.target.value); setSaveStatus('dirty'); }}
                    placeholder="Optional"
                  />
                </div>
                <div className="min-w-0">
                  <Label htmlFor="cover-date" className="mb-2 block text-xs uppercase tracking-[0.12em] text-neutral-500">
                    Date
                  </Label>
                  <Input
                    id="cover-date"
                    value={coverDateText}
                    onChange={e => { setCoverDateText(e.target.value); setSaveStatus('dirty'); }}
                    placeholder="e.g. Summer 2025"
                    disabled={!coverShowDate}
                  />
                </div>
                <div className="flex flex-wrap items-center gap-3 xl:col-span-full">
                  <label className="flex items-center gap-3 rounded-2xl border border-neutral-200 bg-neutral-50 px-4 py-3">
                    <Checkbox
                      checked={coverShowDate}
                      onCheckedChange={(checked) => {
                        const next = checked === true;
                        setCoverShowDate(next);
                        if (next && !(coverDateText || '').trim()) setCoverDateText(defaultCoverDateText());
                        setSaveStatus('dirty');
                      }}
                    />
                    <span className="text-sm font-medium text-neutral-700">Show date</span>
                  </label>
                  {coverWebSrc ? <img src={coverWebSrc} alt="cover" className="h-12 rounded-2xl border border-neutral-200" /> : null}
                </div>
              </CardContent>
            </Card>
          </div>
        )}

        {/* ── Main content area ───────────────────────────────────────── */}
        <main className="flex min-h-0 min-w-0 flex-1 overflow-hidden">
          {/* Empty / build-required state */}
          {!hasPages && !isLoading && (
            <div className="flex flex-1 items-center justify-center p-6">
              <Card className="w-full max-w-2xl rounded-[32px] border-neutral-200/80 bg-white/90 shadow-xl shadow-blue-100/30">
                <CardContent className="flex flex-col items-center gap-6 p-10 text-center">
                  <Badge variant="success">Start Here</Badge>
                  <div className="text-6xl">📷</div>
                  <div>
                    <h1 className="mb-2 text-3xl font-semibold text-neutral-900">Photobook Editor</h1>
                    <p className="mx-auto max-w-lg text-sm leading-6 text-neutral-500">
                      {folder
                        ? `No pages found for "${folder}". Start a build to generate the layout from your Nextcloud folder.`
                        : 'Enter a Nextcloud folder path above or select an album, then click Build.'}
                    </p>
                  </div>
                  {folder && (
                    <Button
                      variant="success"
                      size="lg"
                      disabled={isBuilding}
                      onClick={handleBuild}
                    >
                      Build Photobook
                    </Button>
                  )}
                  {isError && (
                    <Badge variant="warning">
                      Could not load pages. The folder may not have been built yet.
                    </Badge>
                  )}
                </CardContent>
              </Card>
            </div>
          )}

          {/* Loading spinner */}
          {showInitialLoading && (
            <div className="flex-1 flex items-center justify-center">
              <div className="text-sm text-neutral-500">Loading pages…</div>
            </div>
          )}

          {/* Editor + Sidebar */}
          {hasPages && (
            <>
              <div className="flex min-h-0 min-w-0 flex-1 overflow-hidden p-3">
                {!page ? (
                  <div className="flex h-full items-center justify-center text-sm text-neutral-500">No page selected.</div>
                ) : (
                  <div className="flex h-full min-h-0 w-full min-w-0 flex-col items-stretch rounded-[36px] border border-neutral-200/80 bg-[radial-gradient(circle_at_top,_rgba(191,219,254,0.28),_transparent_34%),linear-gradient(180deg,_rgba(255,255,255,0.96),_rgba(248,250,252,0.96))] p-4 shadow-xl shadow-blue-100/20">
                    <div className="mb-3 flex flex-none w-full flex-wrap items-center justify-between gap-3">
                      <div>
                        <div className="text-[11px] font-medium uppercase tracking-[0.18em] text-neutral-500">Workspace Stage</div>
                      </div>
                      <div className="flex flex-wrap items-center gap-2">
                        <Badge>{pageLabel}</Badge>
                        <Badge variant="default">{page?.items?.length || 0} assets</Badge>
                      </div>
                    </div>
                    <EditorCanvas
                      page={page}
                      version={pageVersion}
                      coverMeta={pageIdx === 0 ? coverMetaForEditor : undefined}
                      onChange={(items) => {
                        if (pageId && pageIdx !== 0) {
                          replaceStorePageItems(pageId, items as any[]);
                        }
                        if (suppressNextCanvasDirty.current) {
                          suppressNextCanvasDirty.current = false;
                        } else {
                          setSaveStatus('dirty');
                        }
                        if (pageIdx === 0 && Array.isArray(items) && items.length) {
                          const first = items[0] as any;
                          const existing = coverItemRef.current ?? {};
                          let photo = first?.photo ?? existing.photo ?? null;
                          if ((!photo || !photo.path) && coverPhotoPath) {
                            const fname = (coverPhotoPath.split('/') || []).pop() || undefined;
                            photo = { path: coverPhotoPath, ...(fname ? { filename: fname } : {}) };
                          }
                          coverItemRef.current = { ...existing, ...(first ? { ...first } : {}), slotIndex: 0, photo };
                        }
                      }}
                      onStructuralChange={(items) => {
                        if (!page || !pageId) return;
                        suppressNextCanvasDirty.current = true;
                        window.setTimeout(() => { suppressNextCanvasDirty.current = false; }, 250);
                        if (pageIdx !== 0) replaceStorePageItems(pageId, items as any[]);
                        void persistPage(page, items as any).catch(() => { });
                      }}
                      onSave={async (items: any[]) => {
                        if (!page) return;
                        if (pageIdx === 0) {
                          const first = Array.isArray(items) ? items[0] : currentCoverItem();
                          await persistCover(currentCoverImageRel(), first);
                        } else {
                          await persistPage(page, items as any);
                        }
                      }}
                    />
                  </div>
                )}
              </div>
              <Sidebar
                page={page}
                onSwap={swapItems}
                onReplace={openReplace}
                onUpdateItem={(idx, changes) => {
                  if (!pageId) return;
                  updateStoreItem(pageId, idx, { ...changes, auto: false });
                  setPageVersion(v => v + 1);
                  setSaveStatus('dirty');
                }}
                onTemplateChange={async (tpl) => {
                  if (!page || !pageId || pageIdx === 0) return;

                  let nextSlots = page.slots;

                  try {
                    const groups = templatesQ.data || {} as any;
                    const count = Array.isArray(page.items) ? page.items.length : 0;
                    const arr = (groups[String(count)] || groups[count] || []) as any[];
                    const match = arr.find((t: any) => t.id === tpl);
                    if (match && Array.isArray(match.slots)) {
                      nextSlots = match.slots.map((s: any) => ({
                        x: s.x,
                        y: s.y,
                        w: s.w,
                        h: s.h,
                        ...(s.ar ? { ar: s.ar } : {}),
                      }));
                      updateStoreTemplate(pageId, tpl, nextSlots);
                    } else {
                      updateStoreTemplate(pageId, tpl);
                    }
                  } catch {
                    updateStoreTemplate(pageId, tpl);
                  }

                  const nextPage = {
                    ...page,
                    templateId: tpl,
                    slots: nextSlots,
                    items: (page.items || []).map((item: any, index: number) => ({
                      ...item,
                      slotIndex: index,
                      auto: false,
                    })),
                  };

                  setPageVersion(v => v + 1);
                  await persistPage(nextPage).catch(() => {});
                }}
                onLayoutPreferenceChange={async (preferred) => {
                  if (!page || pageIdx === 0 || !albumHash) return;
                  const templateId = page.templateId || page.template || '';
                  if (!templateId) return;
                  const nextFeedback = {
                    preferred,
                    templateId: preferred ? templateId : null,
                    reason: preferred ? 'user_preferred_layout' : null,
                    updated_at: new Date().toISOString(),
                  };
                  if (pageId) updateStorePageFeedback(pageId, nextFeedback);
                  setPageVersion(v => v + 1);
                  await persistWithStatus(() => PB.saveLayoutFeedback(albumHash, {
                    pageId: pageIdentity(page),
                    page: page.n,
                    action: preferred ? 'prefer_layout' : 'clear_preferred_layout',
                    templateId,
                    reason: 'user_preferred_layout',
                  })).catch(() => { });
                }}
              />
            </>
          )}
        </main>

        <ReplaceDrawer
          open={drawerOpen && canEditPage}
          onClose={() => setDrawerOpen(false)}
          loading={candLoading}
          candidates={candidates}
          onPick={(c, o) => applyReplacement(c, o)}
          onLoadAll={loadAllCandidates}
          showingAll={showingAllCandidates}
        />
        {drawerOpen && !canEditPage && (
          <div className="fixed inset-0 z-50">
            <div className="absolute inset-0 bg-black/40" onClick={() => setDrawerOpen(false)} />
            <div className="absolute right-0 top-0 h-full w-[420px] bg-white shadow-xl border-l border-neutral-200 flex flex-col items-center justify-center gap-3 p-6 text-center">
              <div className="text-sm text-neutral-700">
                {isLoading ? 'Loading pages…' : isError ? 'Unable to load page data.' : 'No page data available.'}
              </div>
              <Button size="sm" variant="secondary" onClick={() => setDrawerOpen(false)}>Close</Button>
            </div>
          </div>
        )}
        {latestPdfUrl && <PdfReadyModal url={latestPdfUrl} onClose={() => setLatestPdfUrl(null)} />}
        <SettingsPanel open={settingsOpen} onClose={() => setSettingsOpen(false)} />
      </div>
    </div>
  );
}
