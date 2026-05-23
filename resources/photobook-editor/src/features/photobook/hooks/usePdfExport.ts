import { useCallback, useState } from 'react';
import { PB } from '../../../lib/api';

export function usePdfExport(albumHash: string) {
  const [latestPdfUrl, setLatestPdfUrl] = useState<string | null>(null);
  const [isExporting, setIsExporting] = useState(false);
  const [exportError, setExportError] = useState<string | null>(null);

  const handleExportPdf = useCallback(async () => {
    if (!albumHash) return;
    setIsExporting(true);
    setExportError(null);

    try {
      const response = await PB.exportPdf(albumHash);
      if (response.ok && response.url) {
        setLatestPdfUrl(response.url);
        return;
      }

      setExportError(response.error || 'Export failed');
    } catch (error) {
      setExportError(error instanceof Error ? error.message : 'Export failed');
    } finally {
      setIsExporting(false);
    }
  }, [albumHash]);

  return {
    latestPdfUrl,
    setLatestPdfUrl,
    isExporting,
    exportError,
    handleExportPdf,
  };
}
