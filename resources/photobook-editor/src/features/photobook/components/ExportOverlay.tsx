type ExportOverlayProps = {
  isExporting: boolean;
};

export default function ExportOverlay({ isExporting }: ExportOverlayProps) {
  if (!isExporting) return null;

  return (
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
  );
}
