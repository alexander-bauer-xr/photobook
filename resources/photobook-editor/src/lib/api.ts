export async function api<T>(url: string, init?: RequestInit): Promise<T> {
  const res = await fetch(url, { headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, credentials: 'same-origin', ...init });
  if (!res.ok) throw new Error(`${res.status} ${res.statusText}`);
  return res.json() as Promise<T>;
}

export interface PrintSettings {
  enabled: boolean;
  bleed_mm: number;
  crop_marks: boolean;
  spine_margin_mm: number;
  safe_zone_mm: number;
}

export interface AppSettings {
  paper: string;
  orientation: string;
  dpi: number;
  page_frame_mm: number;
  page_gap_mm: number;
  print: PrintSettings;
  cover: {
    title: string;
    subtitle: string;
    show_date: boolean;
  };
  nextcloud: {
    configured: boolean;
  };
}

export interface SettingsResponse {
  ok: boolean;
  settings: AppSettings;
}

export type AlbumRecord = {
  hash: string;
  folder: string;
  count: number;
  created_at: string;
};

export type CandidatePhoto = {
  path: string;
  filename: string;
  src?: string | null;
};

export interface LayoutTemplateGroups {
  [count: string]: Array<{ id: string; slots: any[] }>;
}

export const PB = {
  getAlbums: () =>
    api<{ ok: boolean; albums: AlbumRecord[] }>('/api/photobook/albums'),

  getTemplates: () =>
    api<LayoutTemplateGroups>('/api/photobook/templates'),

  getCandidates: (hash: string, page: number, all?: boolean) => {
    const params = new URLSearchParams({ page: String(page) });
    if (all) params.set('all', '1');
    return api<{ ok: boolean; candidates: CandidatePhoto[] }>(
      `/api/photobook/candidates/${hash}?${params.toString()}`,
    );
  },

  getPages: (hash: string) => api<any>(`/api/photobook/pages/${hash}`),
  patchPages: (hash: string, patch: any) => api(`/api/photobook/pages/${hash}`, { method: 'PATCH', body: JSON.stringify(patch) }),
  savePage: (hash: string, page: any) => api<{ ok: boolean }>(`/api/photobook/pages/${hash}`, { method: 'PATCH', body: JSON.stringify({ pages: [page] }) }),
  addPage: (hash: string, page: any) => api(`/api/photobook/pages/${hash}/page`, { method: 'POST', body: JSON.stringify(page) }),
  deletePage: (hash: string, id: string) => api(`/api/photobook/pages/${hash}/page/${id}`, { method: 'DELETE' }),
  setCover: (hash: string, payload: any) => api(`/api/photobook/cover/${hash}`, { method: 'POST', body: JSON.stringify(payload) }),
  saveLayoutFeedback: (hash: string, payload: any) => api<{ ok: boolean; feedback?: any }>(`/api/photobook/feedback/${hash}`, { method: 'POST', body: JSON.stringify(payload) }),
  build: (hash: string, payload: any) => api(`/api/photobook/build/${hash}`, { method: 'POST', body: JSON.stringify(payload) }),
  buildByFolder: (payload: any) => api(`/api/photobook/build-folder`, { method: 'POST', body: JSON.stringify(payload) }) as Promise<{ ok: boolean; status: string; hash: string }>,
  progress: (hash: string) => api(`/api/photobook/progress/${hash}`),
  exportPdf: (hash: string) => api<{ ok: boolean; url?: string; error?: string }>(`/api/photobook/export/${hash}`, { method: 'POST' }),
  getSettings: () => api<SettingsResponse>('/api/photobook/settings'),
  updateSettings: (settings: Partial<AppSettings>) => api<{ ok: boolean; updated: Record<string, any> }>('/api/photobook/settings', { method: 'POST', body: JSON.stringify({ settings }) }),
};
