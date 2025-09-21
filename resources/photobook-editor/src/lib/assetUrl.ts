// Utilities to normalize and build asset URLs for the photobook editor

/**
 * Ensure a URL points to the current origin when it targets our asset endpoint.
 * - If input is root-relative: prefix with window.location.origin
 * - If input is absolute and path starts with /photobook/asset: re-host to current origin
 * - Otherwise, return as-is
 */
export function ensureAssetUrl(input?: string | null): string | null {
  if (!input) return null;
  try {
    const url = new URL(input, window.location.origin);
    if (url.pathname.startsWith('/photobook/asset/')) {
      return `${window.location.origin}${url.pathname}`;
    }
    // For other absolute URLs, keep as-is
    if (url.origin !== 'null') return url.href;
  } catch {
    // ignore and try simple fallback below
  }
  // Fallback: root-relative path
  return input.startsWith('/') ? `${window.location.origin}${input}` : input;
}

/** Build a relative asset URL for a given album hash and relative path */
export function buildAssetUrl(hash: string, relPath?: string | null): string | null {
  if (!hash || !relPath) return null;
  const encRel = String(relPath).split('/').map(encodeURIComponent).join('/');
  const relative = `/photobook/asset/${encodeURIComponent(hash)}/${encRel}`;
  return ensureAssetUrl(relative) ?? relative;
}
