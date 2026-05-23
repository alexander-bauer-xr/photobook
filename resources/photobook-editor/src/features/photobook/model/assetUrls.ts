export function normalizeAssetRel(value?: string | null): string | null {
  if (!value) return null;
  const cleaned = String(value).trim().replace(/\\/g, '/').replace(/^\/+/, '');
  return cleaned || null;
}

export function filePathToAssetUrl(path?: string | null): string | null {
  if (!path) return null;
  const norm = String(path).replace(/^[a-z]+:\/\//i, '').replace(/\\/g, '/');
  const match = norm.match(/\/_cache\/([^/]+)\/(.+)$/);
  if (!match) return null;
  const hash = match[1];
  const rel = match[2];
  const encodedRel = rel.split('/').map(encodeURIComponent).join('/');
  return `/photobook/asset/${encodeURIComponent(hash)}/${encodedRel}`;
}

export function webAssetRelFromUrl(url?: string | null): string | null {
  if (!url) return null;
  const match = String(url).match(/\/photobook\/asset\/[^/]+\/(.+)$/);
  return match ? decodeURIComponent(match[1]) : null;
}

export function relAssetUrl(hash: string, rel?: string | null): string | null {
  const normalizedRel = normalizeAssetRel(rel);
  if (!hash || !normalizedRel) return null;
  const encodedRel = normalizedRel.split('/').map(encodeURIComponent).join('/');
  return `/photobook/asset/${encodeURIComponent(hash)}/${encodedRel}`;
}
