import React from 'react';

type Props = {
  url: string;
  onClose: () => void;
};

export default function PdfReadyModal({ url, onClose }: Props) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      <div className="absolute inset-0 bg-black/40" onClick={onClose} />
      <div className="relative bg-white rounded shadow-xl p-5 w-[420px] max-w-[90vw]">
        <h2 className="text-lg font-semibold mb-2">Your PDF is ready</h2>
        <p className="text-sm text-neutral-700 mb-4">Click the button below to download your generated photo book.</p>
        <div className="flex gap-2 justify-end">
          <button onClick={onClose} className="px-3 py-1 rounded bg-neutral-200">Close</button>
          <a
            href={url}
            target="_blank"
            rel="noopener noreferrer"
            className="px-3 py-1 rounded bg-green-600 text-white"
          >Download PDF</a>
        </div>
      </div>
    </div>
  );
}
