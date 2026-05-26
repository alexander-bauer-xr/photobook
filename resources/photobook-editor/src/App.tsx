import React, { useEffect, useMemo, useRef, useState } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { usePhotobook } from './hooks/usePhotobook';
import EditorCanvas from './components/EditorCanvas';
import Sidebar from './components/Sidebar';
import ReplaceDrawer from './components/ReplaceDrawer';
import PdfReadyModal from './components/PdfReadyModal';
import SettingsPanel from './components/SettingsPanel';
import BuildOverlay from './features/photobook/components/BuildOverlay';
import ExportOverlay from './features/photobook/components/ExportOverlay';
import { useTemplates } from './hooks/useTemplates';
import { useAlbumSelection } from './features/photobook/hooks/useAlbumSelection';
import { useBuildPhotobook } from './features/photobook/hooks/useBuildPhotobook';
import { useCoverEditor } from './features/photobook/hooks/useCoverEditor';
import { useDisplayPages } from './features/photobook/hooks/useDisplayPages';
import { usePagePersistence } from './features/photobook/hooks/usePagePersistence';
import { usePdfExport } from './features/photobook/hooks/usePdfExport';
import { useReplacePhoto } from './features/photobook/hooks/useReplacePhoto';
import { useSaveStatus } from './features/photobook/hooks/useSaveStatus';
import { PB } from './lib/api';
import { Badge } from './components/ui/badge';
import { Button } from './components/ui/button';
import { Card, CardContent } from './components/ui/card';
import { usePB } from './store/photobook';
import TopToolbar from './features/photobook/components/TopToolbar';
import CoverEditorBar from './features/photobook/components/CoverEditorBar';

const qc = new QueryClient();

const defaultCoverDateText = () => new Date().toLocaleString('en-US', { month: 'long', year: 'numeric' });

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
  const {
    drawerOpen,
    setDrawerOpen,
    candidates,
    candLoading,
    showingAllCandidates,
    openReplace,
    loadAllCandidates,
    applyReplacement,
  } = useReplacePhoto({
    albumHash,
    pageIdx,
    page,
    pageId,
    coverItemRef,
    setCoverPath,
    setCoverWebSrc,
    setCoverPhotoPath,
    setPageVersion,
    persistCover,
    persistPage,
    updateStoreItemWith,
  });
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

    void persistPage(page, normalizedItems).catch(() => { });
  };

  const save = async () => {
    if (!page) return;
    if (pageIdx === 0) {
      await persistCover();
      return;
    }
    await persistPage(page);
  };

  return (
    <div className="flex h-screen overflow-hidden bg-[radial-gradient(circle_at_top_left,_rgba(191,219,254,0.35),_transparent_28%),linear-gradient(180deg,_#fafaf9_0%,_#f8fafc_100%)]">
      <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
        <BuildOverlay
          isBuilding={isBuilding}
          buildProgress={buildProgress}
          buildMessage={buildMessage}
        />

        <ExportOverlay isExporting={isExporting} />

        <TopToolbar
          folder={folder}
          albums={albums}
          isBuilding={isBuilding}
          hasPages={hasPages}
          isFetchingPages={isFetchingPages}
          saveStatus={saveStatus}
          pageIdx={pageIdx}
          pageLabel={pageLabel}
          pageCount={displayPages.length}
          canEditPage={canEditPage}
          albumHash={albumHash}
          isExporting={isExporting}
          exportError={exportError}
          onSelectAlbum={(value) => {
            setFolder(value);
            setPageIdx(0);
          }}
          onFolderChange={setFolder}
          onBuild={handleBuild}
          onPrevPage={() => setPageIdx(p => Math.max(0, p - 1))}
          onNextPage={() => setPageIdx(p => p + 1)}
          onSave={async () => {
            try {
              await save();
            } catch (error) {
              alert(error instanceof Error ? error.message : 'Failed to save');
            }
          }}
          onExportPdf={handleExportPdf}
          onOpenSettings={() => setSettingsOpen(true)}
        />

        {hasPages && albumHash && pageIdx === 0 && (
          <CoverEditorBar
            coverTitle={coverTitle}
            coverSubtitle={coverSubtitle}
            coverDateText={coverDateText}
            coverShowDate={coverShowDate}
            coverWebSrc={coverWebSrc}
            onCoverTitleChange={(value) => {
              setCoverTitle(value);
              setSaveStatus('dirty');
            }}
            onCoverSubtitleChange={(value) => {
              setCoverSubtitle(value);
              setSaveStatus('dirty');
            }}
            onCoverDateTextChange={(value) => {
              setCoverDateText(value);
              setSaveStatus('dirty');
            }}
            onCoverShowDateChange={(checked) => {
              setCoverShowDate(checked);
              if (checked && !(coverDateText || '').trim()) {
                setCoverDateText(defaultCoverDateText());
              }
              setSaveStatus('dirty');
            }}
          />
        )}

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
                  await persistPage(nextPage).catch(() => { });
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
