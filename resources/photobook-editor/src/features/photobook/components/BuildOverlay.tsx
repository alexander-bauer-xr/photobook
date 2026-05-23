type BuildOverlayProps = {
  isBuilding: boolean;
  buildProgress: number;
  buildMessage: string;
};

export default function BuildOverlay({
  isBuilding,
  buildProgress,
  buildMessage,
}: BuildOverlayProps) {
  if (!isBuilding) return null;

  return (
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
  );
}
