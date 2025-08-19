export async function api<T>(url: string, init?: RequestInit): Promise<T> {
  const res = await fetch(url, { headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, credentials: 'same-origin', ...init });
  if (!res.ok) throw new Error(`${res.status} ${res.statusText}`);
  return res.json() as Promise<T>;
}

export const PB = {
  getPages: (hash: string) => api<any>(`/api/photobook/pages/${hash}`),
  patchPages: (hash: string, patch: any) => api(`/api/photobook/pages/${hash}`, { method: 'PATCH', body: JSON.stringify(patch) }),
  addPage: (hash: string, page: any) => api(`/api/photobook/pages/${hash}/page`, { method: 'POST', body: JSON.stringify(page) }),
  deletePage: (hash: string, id: string) => api(`/api/photobook/pages/${hash}/page/${id}`, { method: 'DELETE' }),
  setCover: (hash: string, payload: any) => api(`/api/photobook/cover/${hash}`, { method: 'POST', body: JSON.stringify(payload) }),
  build: (hash: string, payload: any) => api(`/api/photobook/build/${hash}`, { method: 'POST', body: JSON.stringify(payload) }),
  progress: (hash: string) => api(`/api/photobook/progress/${hash}`),
};
