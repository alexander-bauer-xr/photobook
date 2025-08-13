<?php

namespace App\Services;

use App\DTO\PhotoDto;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToListContents;

class NextcloudPhotoRepository
{
    public function __construct(
        private FilesystemOperator $disk,
        private ?WebDavClient $dav = null
    ) {
        $this->dav ??= new WebDavClient(); // simple default; or inject via service container
    }

    /** @return PhotoDto[] */
    public function listPhotos(string $folder): array
    {
    $photos = [];
    logger()->info('NC: listPhotos start', ['folder_input' => $folder]);

        // Normalize: accept human input with encoded characters
        $folder = ltrim(rawurldecode($folder), '/');

        // --- 1) Try Flysystem with several variants ---
        $iter = null;
        $candidates = [];
        $base = rtrim($folder, '/');
        $encoded = preg_replace_callback('/[^A-Za-z0-9\/._-]/', fn($m) => rawurlencode($m[0]), $base);

        foreach ([$base, $encoded] as $b) {
            $candidates[] = [$b, false];
            $candidates[] = [$b.'/', false];
            $candidates[] = [$b, true];
            $candidates[] = [$b.'/', true];
        }
    $variantLog = array_map(fn($c) => ['dir' => $c[0], 'deep' => $c[1]], $candidates);
    logger()->debug('NC: candidates prepared', ['variants' => $variantLog]);

        foreach ($candidates as [$dir, $deep]) {
            logger()->debug('NC: trying Flysystem listContents', ['dir' => $dir, 'deep' => $deep]);
            try {
                $iter = $this->disk->listContents($dir, $deep);
                logger()->debug('NC: Flysystem listContents obtained', ['dir' => $dir, 'deep' => $deep]);
                break; // success
            } catch (UnableToListContents $e) {
                logger()->warning('NC: Flysystem UnableToListContents', ['dir' => $dir, 'deep' => $deep, 'error' => $e->getMessage()]);
                $iter = null; // try next
            } catch (\Throwable $e) {
                logger()->warning('NC: Flysystem listContents Throwable', ['dir' => $dir, 'deep' => $deep, 'error' => $e->getMessage()]);
                $iter = null;
            }
        }

        if ($iter !== null) {
            try {
                $iterCount = 0; $accepted = 0;
                foreach ($iter as $item) {
                    $isFile = method_exists($item, 'isFile') ? $item->isFile()
                        : (method_exists($item, 'type') ? ($item->type() === 'file') : false);
                    if (!$isFile) { $iterCount++; continue; }

                    $path = $item->path();
                    $mime = $this->safeMime($path);

                    if ($mime && preg_match('#^image/(jpeg|png|webp)$#', $mime)) {
                        $photos[] = PhotoDto::fromArray([
                            'path' => $path,
                            'filename' => basename($path),
                            'mime' => $mime,
                        ]);
                        $accepted++;
                    }
                    $iterCount++;
                }

                // If we found some via Flysystem, return them
                if ($photos) {
                    logger()->info('NC: Flysystem photos found', ['count' => count($photos), 'iterated' => $iterCount, 'accepted' => $accepted]);
                    return $photos;
                }
            } catch (UnableToListContents $e) {
                // ignore and fall back
                logger()->warning('NC: iteration threw UnableToListContents, falling back', ['error' => $e->getMessage()]);
                $photos = [];
            } catch (\Throwable $e) {
                logger()->warning('NC: iteration threw Throwable, falling back', ['error' => $e->getMessage()]);
                $photos = [];
            }
        }

        // --- 2) Fallback: WebDAV PROPFIND Depth:1 via Guzzle ---
        logger()->info('NC: falling back to WebDAV PROPFIND', ['folder' => $folder]);
        $davItems = [];
        try {
            $davItems = $this->dav->listDepth1($folder);
        } catch (\Throwable $e) {
            logger()->error('NC: WebDAV PROPFIND fallback failed', ['folder' => $folder, 'error' => $e->getMessage()]);
            $davItems = [];
        }

        logger()->info('NC: WebDAV PROPFIND items', ['count' => count($davItems)]);
    if (!empty($davItems)) {
            $sample = array_slice($davItems, 0, 3);
            logger()->debug('NC: DAV sample', ['items' => $sample]);
        }
        $davAccepted = 0;
    foreach ($davItems as $it) {
            $mime = $it['mime'] ?? '';
            if (!$mime) {
                // infer from extension
                $ext = strtolower(pathinfo($it['path'] ?? '', PATHINFO_EXTENSION));
                $mime = match ($ext) {
                    'jpg','jpeg' => 'image/jpeg',
                    'png'        => 'image/png',
                    'webp'       => 'image/webp',
                    default      => '',
                };
            }
            if (!preg_match('#^image/(jpeg|png|webp)$#', $mime)) continue;

            $photos[] = PhotoDto::fromArray([
                'path' => $it['path'],                // decoded relative path (e.g. Alben/#1/file.jpg)
                'filename' => basename($it['path']),
                'mime' => $mime,
                'etag' => $it['etag'] ?? null,
                'fileSize' => isset($it['size']) ? (int) $it['size'] : null,
            ]);
            $davAccepted++;
        }
        logger()->info('NC: WebDAV accepted images', ['accepted' => $davAccepted]);

    logger()->info('NC: listPhotos finish', ['total' => count($photos)]);
        return $photos;
    }

    private function safeMime(string $path): ?string
    {
        try {
            return $this->disk->mimeType($path);
        } catch (\Throwable $e) {
            logger()->debug('NC: mimeType fallback by extension', ['path' => $path, 'error' => $e->getMessage()]);
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            return match ($ext) {
                'jpg','jpeg' => 'image/jpeg',
                'png'        => 'image/png',
                'webp'       => 'image/webp',
                default      => null,
            };
        }
    }
}
