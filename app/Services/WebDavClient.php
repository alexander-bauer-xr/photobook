<?php

namespace App\Services;

use GuzzleHttp\Client;

class WebDavClient
{
    private Client $http;
    private string $base;

    public function __construct(
        ?string $baseUri = null,
        ?string $user = null,
        ?string $pass = null
    ) {
        $this->base = rtrim($baseUri ?? env('NEXTCLOUD_BASE_URI'), '/') . '/';
        $this->http = new Client([
            'base_uri' => $this->base,
            'auth'     => [$user ?? env('NEXTCLOUD_USERNAME'), $pass ?? env('NEXTCLOUD_PASSWORD')],
        ]);
    }

    /**
     * PROPFIND Depth:1 and return array of immediate children:
     * [
     *   ['path' => 'Alben/#1/file.jpg', 'mime' => 'image/jpeg', 'size' => 1234],
     *   ...
     * ]
     */
    public function listDepth1(string $folder): array
    {
        $folder = trim($folder, '/');
        $encoded = preg_replace_callback('/[^A-Za-z0-9\/_\.-]/', fn($m) => rawurlencode($m[0]), $folder) . '/';
        \Log::debug('NC DAV: PROPFIND Depth:1', ['folder' => $folder, 'encoded' => $encoded, 'base' => $this->base]);

        $res = $this->http->request('PROPFIND', $encoded, [
            'headers' => ['Depth' => '1'],
        ]);

        $status = $res->getStatusCode();
        $ctype  = $res->getHeaderLine('Content-Type');
        $xml    = (string) $res->getBody();
        \Log::debug('NC DAV: xml length', ['bytes' => strlen($xml), 'status' => $status, 'ctype' => $ctype]);

        // Try to parse XML robustly (namespace-agnostic)
        $sx = null;
        $prev = libxml_use_internal_errors(true);
        try {
            $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOBLANKS);
        } catch (\Throwable $e) {
            // ignore, we'll try a fallback
        }
        if (!$sx) {
            $errs = array_slice(libxml_get_errors() ?: [], 0, 3);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            \Log::warning('NC DAV: simplexml parse failed', [
                'errors' => array_map(fn($e) => trim($e->message), $errs),
                'snippet' => substr($xml, 0, 300),
            ]);
            // Fallback: extract hrefs via regex as a last resort
            $items = [];
            if (preg_match_all('#<[^:>]*:?href>([^<]+)</[^>]*href>#i', $xml, $m)) {
                foreach ($m[1] as $href) {
                    $rel = $this->hrefToRelativePath($href);
                    $relDec = rawurldecode($rel);
                    // Skip the folder itself
                    if (rtrim($relDec, '/') === $folder) { continue; }
                    if (!str_starts_with($relDec, $folder . '/')) { continue; }
                    $items[] = [
                        'path' => $relDec,
                        'mime' => null,
                        'size' => null,
                    ];
                }
                \Log::debug('NC DAV: parsed items (regex fallback)', ['count' => count($items)]);
            }
            return $items;
        }
        libxml_use_internal_errors($prev);

        // Use namespace-agnostic XPath to be resilient to different prefixes
        $sx->registerXPathNamespace('d', 'DAV:');
        $items = [];

    $responses = $sx->xpath('//*[local-name()="response"]') ?: [];
        if (!empty($responses)) {
            $hrefs = [];
            foreach (array_slice($responses, 0, 5) as $resp0) {
                $hn = ($resp0->xpath('*[local-name()="href"]')[0] ?? null);
                if ($hn) { $hrefs[] = (string) $hn; }
            }
            \Log::debug('NC DAV: response nodes', ['count' => count($responses), 'href_sample' => $hrefs]);
        } else {
            \Log::debug('NC DAV: response nodes', ['count' => 0]);
        }
        $count = 0;
        foreach ($responses as $resp) {
            $hrefNode = ($resp->xpath('*[local-name()="href"]')[0] ?? null);
            if (!$hrefNode) continue;
            $href = (string) $hrefNode;

            $mimeNode = ($resp->xpath('.//*[local-name()="getcontenttype"]')[0] ?? null);
            $sizeNode = ($resp->xpath('.//*[local-name()="getcontentlength"]')[0] ?? null);
            $etagNode = ($resp->xpath('.//*[local-name()="getetag"]')[0] ?? null);
            $mime = $mimeNode !== null ? (string) $mimeNode : '';
            $size = $sizeNode !== null ? (int) $sizeNode : 0;
            $etag = $etagNode !== null ? trim((string) $etagNode, '"') : '';

            $rel = $this->hrefToRelativePath($href);
            $relDec = rawurldecode($rel);
            // Skip the folder itself
            if (rtrim($relDec, '/') === $folder) {
                continue;
            }
            if (!str_starts_with($relDec, $folder . '/')) {
                // Only items within the requested folder
                continue;
            }

            $items[] = [
                'path' => $relDec,
                'mime' => $mime ?: null,
                'size' => $size ?: null,
                'etag' => $etag ?: null,
            ];
            $count++;
        }
        \Log::debug('NC DAV: parsed items', ['count' => $count]);
        return $items;
    }

    /** Partial GET: returns up to (end-start+1) bytes and ETag if available */
    public function getPartial(string $path, int $start, int $end): array
    {
        $encoded = preg_replace_callback('/[^A-Za-z0-9\/_\.-]/', fn($m) => rawurlencode($m[0]), ltrim($path, '/'));
        $res = $this->http->request('GET', $encoded, [
            'headers' => [
                'Range' => sprintf('bytes=%d-%d', $start, $end),
            ],
            'http_errors' => false,
        ]);
        $status = $res->getStatusCode();
        if ($status >= 200 && $status < 300 || $status === 206) {
            $etag = $res->getHeaderLine('ETag');
            $etag = $etag ? trim($etag, '"') : null;
            return [
                'bytes' => (string) $res->getBody(),
                'etag' => $etag,
                'status' => $status,
            ];
        }
        return ['bytes' => '', 'etag' => null, 'status' => $status];
    }

    private function hrefToRelativePath(string $href): string
    {
        // e.g. /nextcloud/remote.php/dav/files/alix/Alben/%231/photo.jpg
        $path = parse_url($href, PHP_URL_PATH) ?: $href;
        // Strip anything up to and including /remote.php/dav/files/<user>/
        $rel = preg_replace('#^.*/remote\.php/dav/files/[^/]+/#', '', $path);
        // As a fallback, also try to strip /dav/files/<user>/ if server omits remote.php segment
        if ($rel === $path) {
            $rel = preg_replace('#^.*/dav/files/[^/]+/#', '', $path);
        }
        return $rel;
    }
}
