<?php

namespace Tests\Feature;

use App\Services\PhotoBookBuilder;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PhotoBookBuilderTest extends TestCase
{
    public function test_auto_pick_cover_uses_cached_map_for_converted_images(): void
    {
        $folder = 'TestAlbumNonJpeg';

        $hash = sha1($folder);
        $cacheRoot = storage_path('app/pdf-exports/_cache/' . $hash);
        $imagesDir = $cacheRoot . DIRECTORY_SEPARATOR . 'images';

        File::deleteDirectory($cacheRoot);
        File::makeDirectory($imagesDir, 0775, true, true);

        $photoPath = 'TestAlbumNonJpeg/cover.png';
        $convertedName = sha1($photoPath) . '.jpg';
        $convertedFull = $imagesDir . DIRECTORY_SEPARATOR . $convertedName;

        $jpegBytes = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8fLz9PX29/j5+v/bAEMBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAf/dAAQAA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAhAAAAD6AAAAAP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAQUC/8QAFBEBAAAAAAAAAAAAAAAAAAAAIP/aAAgBAwEBPwE//8QAFBEBAAAAAAAAAAAAAAAAAAAAIP/aAAgBAgEBPwE//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyFf/9k=');
        File::put($convertedFull, $jpegBytes);

        $signature = sha1($photoPath);
        $manifest = [
            'folder' => $folder,
            'signature' => $signature,
            'map' => [
                $photoPath => $convertedName,
            ],
            'created_at' => date(DATE_ATOM),
        ];
        File::put($cacheRoot . DIRECTORY_SEPARATOR . 'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        $pages = [
            [
                'template' => '1up',
                'photos' => [
                    (object) [
                        'path' => $photoPath,
                        'filename' => 'cover.png',
                    ],
                ],
                'items' => [],
                'slots' => [],
            ],
        ];
        $options = ['folder' => $folder];

        $builder = app(PhotoBookBuilder::class);
        $builder->render($pages, $options);

        $pagesJsonPath = $cacheRoot . DIRECTORY_SEPARATOR . 'pages.json';
        $this->assertFileExists($pagesJsonPath);

        $export = json_decode(File::get($pagesJsonPath), true);
        $this->assertIsArray($export);
        $this->assertNotEmpty($export['pages'] ?? []);

        $coverPage = $export['pages'][0];
        $this->assertSame(0, $coverPage['n']);
        $this->assertNotEmpty($coverPage['items']);

        $coverItem = $coverPage['items'][0];
        $this->assertSame('images/' . $convertedName, $coverItem['rel']);
        $this->assertStringStartsWith('file:///', $coverItem['src']);
        $this->assertStringEndsWith($convertedName, $coverItem['src']);

        File::deleteDirectory($cacheRoot);
    }
}
