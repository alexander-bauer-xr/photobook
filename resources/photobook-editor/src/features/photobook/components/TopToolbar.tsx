import { Badge } from '../../../components/ui/badge';
import { Button } from '../../../components/ui/button';
import { Input } from '../../../components/ui/input';
import { Separator } from '../../../components/ui/separator';

type AlbumOption = {
    hash: string;
    folder?: string;
    count?: number;
}

type SaveStatus = 'idle' | 'dirty' | 'saving' | 'saved' | 'error';

type TopToolbarProps = {
    folder: string;
    albums: AlbumOption[];
    isBuilding: boolean;
    hasPages: boolean;
    isFetchingPages: boolean;
    saveStatus: SaveStatus;
    pageIdx: number;
    pageLabel: string;
    pageCount: number;
    canEditPage: boolean;
    albumHash: string;
    isExporting: boolean;
    exportError: string | null;
    onSelectAlbum: (value: string) => void;
    onFolderChange: (value: string) => void;
    onBuild: () => void;
    onPrevPage: () => void;
    onNextPage: () => void;
    onSave: () => void | Promise<void>;
    onExportPdf: () => void | Promise<void>;
    onOpenSettings: () => void;
};

const selectControlClassName =
    'h-10 rounded-xl border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-900 shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-neutral-300';

export default function TopToolbar({
    folder,
    albums,
    isBuilding,
    hasPages,
    isFetchingPages,
    saveStatus,
    pageIdx,
    pageLabel,
    pageCount,
    canEditPage,
    albumHash,
    isExporting,
    exportError,
    onSelectAlbum,
    onFolderChange,
    onBuild,
    onPrevPage,
    onNextPage,
    onSave,
    onExportPdf,
    onOpenSettings,
}: TopToolbarProps) {
    return (
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
                        onChange={e => onSelectAlbum(e.target.value)}
                    >
                        <option value="">
                            Select album ...
                        </option>

                        {albums.map(album => (
                            <option key={album.hash} value={album.folder || album.hash}>{album.folder || album.hash} ({album.count})</option>
                        ))}
                    </select>
                    <Input
                        className="min-w-[14rem] flex-[1.2] md:max-w-[22rem]"
                        value={folder}
                        onChange={e => onFolderChange(e.target.value)}
                        placeholder="or type folder path…"
                    />

                    <Button
                        variant="success"
                        disabled={!folder || isBuilding}
                        onClick={onBuild}
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
                                    onClick={onPrevPage}
                                >
                                    Prev
                                </Button>

                                <Badge>{pageLabel}</Badge>

                                <Button
                                    size="sm"
                                    variant="secondary"
                                    disabled={pageCount <= pageIdx + 1}
                                    onClick={onNextPage}
                                >
                                    Next
                                </Button>
                            </div>

                            <Separator orientation="vertical" className="mx-1 hidden h-8 md:block" />

                            <Button
                                variant="brand"
                                disabled={!canEditPage || (pageIdx === 0 && !albumHash)}
                                onClick={() => { void onSave(); }}
                            >
                                {pageIdx === 0 ? 'Save cover' : 'Save page'}
                            </Button>

                            <Button
                                variant="outline"
                                disabled={isExporting || !albumHash}
                                onClick={() => { void onExportPdf(); }}
                                title="Export to PDF via Playwright"
                            >
                                {isExporting ? 'Exporting…' : 'Export PDF'}
                            </Button>

                            {exportError && <Badge variant="warning">{exportError}</Badge>}
                        </>
                    )}

                    <Button variant="outline" onClick={onOpenSettings} title="Settings">
                        Settings
                    </Button>
                </div>
            </div>
        </header>
    )
}