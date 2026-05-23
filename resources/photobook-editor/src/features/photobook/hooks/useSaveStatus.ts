import { useCallback, useEffect, useRef, useState } from 'react';

export type SaveStatus = 'idle' | 'dirty' | 'saving' | 'saved' | 'error';

export function useSaveStatus() {
  const [saveStatus, setSaveStatus] = useState<SaveStatus>('idle');
  const saveStatusTimer = useRef<number | null>(null);

  useEffect(() => () => {
    if (saveStatusTimer.current) window.clearTimeout(saveStatusTimer.current);
  }, []);

  const setSavedSoon = useCallback(() => {
    if (saveStatusTimer.current) window.clearTimeout(saveStatusTimer.current);
    setSaveStatus('saved');
    saveStatusTimer.current = window.setTimeout(() => setSaveStatus('idle'), 1400);
  }, []);

  const persistWithStatus = useCallback(async (task: () => Promise<any>) => {
    if (saveStatusTimer.current) window.clearTimeout(saveStatusTimer.current);
    setSaveStatus('saving');
    try {
      const result = await task();
      setSavedSoon();
      return result;
    } catch (error) {
      setSaveStatus('error');
      throw error;
    }
  }, [setSavedSoon]);

  return {
    saveStatus,
    setSaveStatus,
    setSavedSoon,
    persistWithStatus,
  };
}
