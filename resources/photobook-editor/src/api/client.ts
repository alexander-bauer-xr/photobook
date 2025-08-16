/*
Copilot prompt:
Tiny fetch wrapper for:
- GET /photobook/pages?folder=...
- POST /photobook/override
- POST /photobook/save-page
Return JSON, throw on !ok. No external libs.
*/
import type { PagesFile, OverridePayload, SavePagePayload } from './types';

async function okJson<T>(res: Response): Promise<T> {
  if (!res.ok) throw new Error(`HTTP ${res.status} ${await res.text().catch(()=> '')}`);
  return res.json() as Promise<T>;
}

export const api = {
  async getPages(folder?: string): Promise<PagesFile | null> {
  const resp = await fetch('/photobook/pages?folder=' + encodeURIComponent(folder || ''), { headers: { Accept: 'application/json' }});
  if (resp.status === 404) return null;
  const raw = await okJson<any>(resp);
  // Accept both { ok, data } and raw PagesFile
  if (raw && typeof raw === 'object' && 'ok' in raw && 'data' in raw) return (raw.data as PagesFile) ?? null;
  return raw as PagesFile;
  },
  async getAlbums(): Promise<{ ok: boolean; albums: { hash: string; folder: string; count: number; created_at: string }[] }> {
    return okJson(await fetch('/photobook/albums', { headers: { Accept: 'application/json' } }));
  },
  async getCandidates(folder: string, page: number): Promise<{ ok: boolean; candidates: { path: string; filename: string; src?: string | null }[] }> {
    const u = '/photobook/candidates?folder=' + encodeURIComponent(folder) + '&page=' + encodeURIComponent(String(page));
    return okJson(await fetch(u, { headers: { Accept: 'application/json' } }));
  },
  async overrideTemplate(payload: OverridePayload) {
    return okJson<{ok: boolean}>(await fetch('/photobook/override', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)}));
  },
  async savePage(payload: SavePagePayload) {
    return okJson<{ok: boolean}>(await fetch('/photobook/save-page', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)}));
  },
};
