<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class InitPhotoBookProject extends Command
{
    protected $signature = 'photobook:init {--force : Overwrite existing files}';
    protected $description = 'Scaffold Nextcloud-based photo book PDF generator with Copilot prompts';

    public function handle(Filesystem $fs): int
    {
        $force = (bool) $this->option('force');

        // 1) Directories
        $dirs = [
            app_path('DTO'),
            app_path('Services'),
            app_path('Jobs'),
            app_path('Http/Controllers'),
            resource_path('views/photobook'),
            base_path('stubs/photobook'),
            config_path(),
            storage_path('app/pdf-exports'),
        ];
        foreach ($dirs as $dir) {
            if (!$fs->isDirectory($dir)) {
                $fs->makeDirectory($dir, 0755, true);
                $this->line("Created: $dir");
            }
        }

        // 2) Files to write (path => contents)
        $files = [
            base_path('.env.example') => $this->envExample(),
            config_path('filesystems.php') => $this->patchedFilesystems($fs->get(config_path('filesystems.php'))),
            config_path('photobook.php') => $this->configPhotobook(),
            base_path('routes/web.php') => $this->appendRoutes($fs->get(base_path('routes/web.php'))),

            app_path('DTO/PhotoDto.php') => $this->dtoPhoto(),
            app_path('Services/NextcloudPhotoRepository.php') => $this->svcNextcloudRepo(),
            app_path('Services/ImageProbe.php') => $this->svcImageProbe(),
            app_path('Services/LayoutPlanner.php') => $this->svcLayoutPlanner(),
            app_path('Services/PhotoBookBuilder.php') => $this->svcPhotoBookBuilder(),
            app_path('Services/PdfRenderer.php') => $this->svcPdfRenderer(),
            app_path('Jobs/BuildPhotoBook.php') => $this->jobBuildPhotoBook(),
            app_path('Http/Controllers/PhotoBookController.php') => $this->ctrlPhotoBook(),

            resource_path('views/photobook/cover.blade.php') => $this->viewCover(),
            resource_path('views/photobook/page-1up.blade.php') => $this->view1up(),
            resource_path('views/photobook/page-2up.blade.php') => $this->view2up(),
            resource_path('views/photobook/page-3up.blade.php') => $this->view3up(),
            resource_path('views/photobook/layout.blade.php') => $this->viewLayout(),
        ];

        foreach ($files as $path => $content) {
            if ($fs->exists($path) && !$force) {
                $this->warn("Skipped (exists): $path  (use --force to overwrite)");
                continue;
            }
            $fs->put($path, $content);
            $this->info("Wrote: $path");
        }

        $this->newLine();
        $this->components->info('Done!');
        $this->line('Next steps:');
        $this->line('  1) composer require dompdf/dompdf league/flysystem-webdav intervention/image');
        $this->line('  2) Set NEXTCLOUD_* in your .env');
        $this->line('  3) php artisan queue:table && php artisan migrate');
        $this->line('  4) php artisan serve and open /photobook to test');

        return self::SUCCESS;
    }

    // ---------- Content builders (each includes a Copilot system-style prompt at top) ----------

    private function envExample(): string
    {
        return <<<ENV
# --- Copilot prompt (READ-ONLY) ---
# You are setting .env values for a Laravel app that generates a photo book PDF from
# a Nextcloud folder via WebDAV. Provide secure example values. Do not remove existing keys.

APP_NAME=Laravel
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost

# Nextcloud WebDAV connection
NEXTCLOUD_BASE_URI="https://cloud.example.com/remote.php/dav/files/username/"
NEXTCLOUD_USERNAME="username"
NEXTCLOUD_PASSWORD="app-password-or-token"

# Photobook defaults
PHOTOBOOK_FOLDER="Photos/2025/Family"
PHOTOBOOK_PAPER="a4"
PHOTOBOOK_DPI=150
ENV;
    }

    private function patchedFilesystems(string $current): string
    {
        // Add nextcloud + pdf_exports disks if not present.
        if (str_contains($current, "'nextcloud' => [")) {
            return $current; // already patched
        }

        $patch = <<<PHP
        'nextcloud' => [
            'driver'   => 'webdav',
            'baseUri'  => env('NEXTCLOUD_BASE_URI'),
            'userName' => env('NEXTCLOUD_USERNAME'),
            'password' => env('NEXTCLOUD_PASSWORD'),
        ],
        'pdf_exports' => [
            'driver' => 'local',
            'root'   => storage_path('app/pdf-exports'),
            'throw'  => false,
        ],
PHP;

        return preg_replace(
            "/('disks'\\s*=>\\s*\\[)/",
            "\$1\n$patch\n",
            $current,
            1
        );
    }

    private function configPhotobook(): string
    {
        return <<<PHP
<?php
return [
    // --- Copilot prompt ---
    // You are configuring defaults for a photo book builder.
    // Keep keys simple, strictly typed, and documented.

    'folder' => env('PHOTOBOOK_FOLDER', 'Photos'),
    'paper'  => env('PHOTOBOOK_PAPER', 'a4'),   // a4 | a3
    'dpi'    => (int) env('PHOTOBOOK_DPI', 150),
    'margin_mm' => 10,
    'cover' => [
        'title' => 'My Photo Book',
        'subtitle' => 'Generated by Laravel',
        'show_date' => true,
    ],
];
PHP;
    }

    private function appendRoutes(string $current): string
    {
        if (str_contains($current, "Route::get('/photobook'")) {
            return $current;
        }
        $add = <<<PHP

use App\Http\Controllers\PhotoBookController;

Route::get('/photobook', [PhotoBookController::class, 'index']);
Route::post('/photobook/build', [PhotoBookController::class, 'build']);
PHP;
        return $current . "\n" . $add . "\n";
    }

    private function dtoPhoto(): string
    {
        return <<<PHP
<?php

namespace App\DTO;

/**
 * Copilot prompt:
 * Build an immutable PhotoDto value object with:
 * - string \$path, string \$filename, ?string \$mime, ?int \$width, ?int \$height, ?float \$ratio, ?\\DateTimeImmutable \$takenAt
 * - a static constructor: fromArray(array \$data): self
 * - helpers: isPortrait(), isLandscape(), isSquare()
 * - ensure ratio = width/height when both are present (float with 4 decimals)
 */
final class PhotoDto
{
    public function __construct(
        public readonly string \$path,
        public readonly string \$filename,
        public readonly ?string \$mime = null,
        public readonly ?int \$width = null,
        public readonly ?int \$height = null,
        public readonly ?float \$ratio = null,
        public readonly ?\\DateTimeImmutable \$takenAt = null,
    ) {}

    public static function fromArray(array \$d): self
    {
        return new self(
            \$d['path'],
            \$d['filename'] ?? basename(\$d['path']),
            \$d['mime'] ?? null,
            \$d['width'] ?? null,
            \$d['height'] ?? null,
            \$d['ratio'] ?? (isset(\$d['width'], \$d['height']) && \$d['height'] ? round(\$d['width']/\$d['height'], 4) : null),
            \$d['takenAt'] ?? null,
        );
    }

    public function isPortrait(): bool  { return \$this->ratio !== null && \$this->ratio < 0.9; }
    public function isLandscape(): bool { return \$this->ratio !== null && \$this->ratio > 1.1; }
    public function isSquare(): bool    { return \$this->ratio !== null && \$this->ratio >= 0.9 && \$this->ratio <= 1.1; }
}
PHP;
    }

    private function svcNextcloudRepo(): string
    {
        return <<<PHP
<?php

namespace App\Services;

use App\DTO\PhotoDto;
use League\Flysystem\FilesystemOperator;

/**
 * Copilot prompt:
 * Implement a repository that lists image files in a Nextcloud folder using Flysystem (webdav).
 * - Inject FilesystemOperator \$disk via constructor (bound to 'nextcloud')
 * - listPhotos(string \$folder): PhotoDto[]  (filter by mime image/jpeg|png|webp)
 * - Return bare DTOs (no width/height yet)
 */
class NextcloudPhotoRepository
{
    public function __construct(private FilesystemOperator \$disk) {}

    /** @return PhotoDto[] */
    public function listPhotos(string \$folder): array
    {
        \$photos = [];
        foreach (\$this->disk->listContents(\$folder, false) as \$item) {
            if (\$item->isFile()) {
                \$mime = \$item->mimeType() ?? '';
                if (preg_match('#^image/(jpeg|png|webp)\$#', \$mime)) {
                    \$photos[] = PhotoDto::fromArray([
                        'path' => \$item->path(),
                        'filename' => basename(\$item->path()),
                        'mime' => \$mime,
                    ]);
                }
            }
        }
        return \$photos;
    }
}
PHP;
    }

    private function svcImageProbe(): string
    {
        return <<<PHP
<?php

namespace App\Services;

use App\DTO\PhotoDto;
use Illuminate\Support\Facades\Cache;
use League\Flysystem\FilesystemOperator;

/**
 * Copilot prompt:
 * Implement ImageProbe to enrich PhotoDto with width/height/ratio using Intervention Image.
 * - Inject nextcloud FilesystemOperator and cache
 * - fillDimensions(PhotoDto[]): PhotoDto[]
 * - Try to stream bytes and use exif_imagetype/getimagesizefromstring
 * - Cache by key "probe:{path}" for 1 day
 */
class ImageProbe
{
    public function __construct(private FilesystemOperator \$disk) {}

    /** @param PhotoDto[] \$photos */
    public function fillDimensions(array \$photos): array
    {
        return array_map(function(PhotoDto \$p){
            \$key = 'probe:'.\$p->path;
            \$meta = Cache::remember(\$key, 86400, function() use (\$p) {
                \$stream = \$this->disk->readStream(\$p->path);
                if (!\$stream) return null;
                \$bytes = stream_get_contents(\$stream);
                if (!\$bytes) return null;
                \$info = @getimagesizefromstring(\$bytes);
                if (!\$info) return null;
                return ['w' => \$info[0] ?? null, 'h' => \$info[1] ?? null];
            });
            if (!\$meta) return \$p;
            return PhotoDto::fromArray([
                'path' => \$p->path,
                'filename' => \$p->filename,
                'mime' => \$p->mime,
                'width' => \$meta['w'],
                'height' => \$meta['h'],
                'ratio' => (\$meta['h'] ?? 0) ? round((\$meta['w']/\$meta['h']), 4) : null,
                'takenAt' => \$p->takenAt,
            ]);
        }, \$photos);
    }
}
PHP;
    }

    private function svcLayoutPlanner(): string
    {
        return <<<PHP
<?php

namespace App\Services;

use App\DTO\PhotoDto;

/**
 * Copilot prompt:
 * Produce a simple page plan:
 * - plan(array \$photos, array \$options = []): array
 * - Each page: ['template' => '1up|2up|3up', 'photos' => PhotoDto[]]
 * - Greedy: take 3 squares as 3up; else 2 same-orientation as 2up; else 1up
 */
class LayoutPlanner
{
    /** @param PhotoDto[] \$photos */
    public function plan(array \$photos, array \$options = []): array
    {
        \$pages = [];
        \$queue = array_values(\$photos);

        while (\$queue) {
            \$a = array_shift(\$queue);
            if (count(\$queue) >= 2 && \$a->isSquare() && \$queue[0]->isSquare() && \$queue[1]->isSquare()) {
                \$b = array_shift(\$queue);
                \$c = array_shift(\$queue);
                \$pages[] = ['template' => '3up', 'photos' => [\$a,\$b,\$c]];
                continue;
            }
            if (count(\$queue) >= 1) {
                \$b = \$queue[0];
                if ((\$a->isLandscape() && \$b->isLandscape()) || (\$a->isPortrait() && \$b->isPortrait())) {
                    array_shift(\$queue);
                    \$pages[] = ['template' => '2up', 'photos' => [\$a,\$b]];
                    continue;
                }
            }
            \$pages[] = ['template' => '1up', 'photos' => [\$a]];
        }

        return \$pages;
    }
}
PHP;
    }

    private function svcPhotoBookBuilder(): string
    {
        return <<<PHP
<?php

namespace App\Services;

/**
 * Copilot prompt:
 * Build full HTML for the photo book using Blade partials:
 * - render(array \$pages, array \$options): [string \$html, string \$assetsDir]
 * - For now, use public URLs from Nextcloud (or temporary local copies later)
 * - Include a cover page and then loop pages with @include by template name
 */
class PhotoBookBuilder
{
    public function render(array \$pages, array \$options): array
    {
        \$html = view('photobook.layout', [
            'options' => \$options,
            'pages' => \$pages,
        ])->render();

        return [\$html, sys_get_temp_dir()];
    }
}
PHP;
    }

    private function svcPdfRenderer(): string
    {
        return <<<PHP
<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

/**
 * Copilot prompt:
 * Render HTML to PDF using Dompdf.
 * - renderTo(string \$fullPath, string \$html, string \$paper='a4', string \$orientation='portrait', int \$dpi=150): void
 * - Save to pdf_exports disk if path is relative
 */
class PdfRenderer
{
    public function renderTo(string \$fullPath, string \$html, string \$paper='a4', string \$orientation='portrait', int \$dpi=150): void
    {
        \$opts = new Options();
        \$opts->set('isRemoteEnabled', true);
        \$opts->set('dpi', \$dpi);

        \$dompdf = new Dompdf(\$opts);
        \$dompdf->loadHtml(\$html);
        \$dompdf->setPaper(\$paper, \$orientation);
        \$dompdf->render();

        if (!str_starts_with(\$fullPath, '/')) {
            \$disk = Storage::disk('pdf_exports');
            \$disk->put(\$fullPath, \$dompdf->output());
        } else {
            file_put_contents(\$fullPath, \$dompdf->output());
        }
    }
}
PHP;
    }

    private function jobBuildPhotoBook(): string
{
    return <<<'PHP'
<?php

namespace App\Jobs;

use App\Services\{NextcloudPhotoRepository, ImageProbe, LayoutPlanner, PhotoBookBuilder, PdfRenderer};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;

class BuildPhotoBook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public array $options = []) {}

    public function handle(
        NextcloudPhotoRepository $repo,
        ImageProbe $probe,
        LayoutPlanner $planner,
        PhotoBookBuilder $builder,
        PdfRenderer $pdf
    ): void {
        $folder = $this->options['folder'] ?? Config::get('photobook.folder');
        $paper  = $this->options['paper'] ?? Config::get('photobook.paper');
        $dpi    = (int) ($this->options['dpi'] ?? Config::get('photobook.dpi'));

        $photos = $repo->listPhotos($folder);
        $photos = $probe->fillDimensions($photos);
        usort($photos, fn($a,$b) => strcmp($a->filename, $b->filename));

        $pages = $planner->plan($photos, $this->options);
        [$html, $assetsDir] = $builder->render($pages, $this->options);

        $name = 'book-'.now()->format('Ymd-His').'.pdf';
        $pdf->renderTo($name, $html, $paper, 'portrait', $dpi);

        logger()->info('Photobook generated at storage/app/pdf-exports/'.$name);
    }
}
PHP;
}

    private function ctrlPhotoBook(): string
    {
        return <<<PHP
<?php

namespace App\Http\Controllers;

use App\Jobs\BuildPhotoBook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * Copilot prompt:
 * Build a small controller with:
 * - index(): show a minimal form (folder, paper, dpi) + "Build" button
 * - build(Request): dispatch BuildPhotoBook job and redirect back with flash
 */
class PhotoBookController extends Controller
{
    public function index()
    {
        return view('photobook.cover', [
            'defaults' => [
                'folder' => Config::get('photobook.folder'),
                'paper' => Config::get('photobook.paper'),
                'dpi' => Config::get('photobook.dpi'),
            ]
        ]);
    }

    public function build(Request \$request)
    {
        BuildPhotoBook::dispatch([
            'folder' => \$request->string('folder')->toString(),
            'paper'  => \$request->string('paper')->toString(),
            'dpi'    => (int) \$request->input('dpi', 150),
        ]);

        return back()->with('status', 'Build started. Check logs.');
    }
}
PHP;
    }

    private function viewLayout(): string
    {
        return <<<BLADE
{{-- Copilot prompt:
Create the main layout for the PDF:
- @include a simple cover page
- Loop over \$pages and include 'photobook/page-{{template}}.blade.php'
- Use minimal CSS for print, margins from config
--}}
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
@page { margin: 10mm; }
body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin:0; }
.page { page-break-after: always; display:flex; gap:6mm; }
.slot { flex:1 1 0; display:flex; align-items:center; justify-content:center; }
.slot img { width:100%; height:100%; object-fit: cover; }
.caption { font-size: 10pt; margin-top: 2mm; }
</style>
</head>
<body>
@include('photobook.cover')

@foreach(\$pages as \$page)
    @include('photobook.page-' . \$page['template'], ['photos' => \$page['photos']])
@endforeach

</body>
</html>
BLADE;
    }

    private function viewCover(): string
    {
        return <<<BLADE
{{-- Copilot prompt:
Create a minimal cover with title/subtitle and today's date if configured.
--}}
@if(session('status'))
    <div style="background:#e8fff3;padding:8px 12px;margin:6px 0;border:1px solid #bde5c8;">
        {{ session('status') }}
    </div>
@endif

<form method="post" action="/photobook/build" style="margin:12px 0;">
    @csrf
    <label>Nextcloud folder:
        <input type="text" name="folder" value="{{ \$defaults['folder'] }}" style="width:320px;">
    </label>
    <label>Paper:
        <select name="paper">
            <option value="a4" {{ \$defaults['paper']=='a4'?'selected':'' }}>A4</option>
            <option value="a3" {{ \$defaults['paper']=='a3'?'selected':'' }}>A3</option>
        </select>
    </label>
    <label>DPI:
        <input type="number" name="dpi" value="{{ \$defaults['dpi'] }}" min="72" max="300">
    </label>
    <button type="submit">Build PDF</button>
</form>

<div class="page" style="align-items:center; justify-content:center;">
    <div style="text-align:center;">
        <h1 style="margin:0;">{{ config('photobook.cover.title') }}</h1>
        <p style="margin:.5em 0 0;">{{ config('photobook.cover.subtitle') }}</p>
        @if (config('photobook.cover.show_date'))
            <p style="margin-top:2em; font-size: 10pt;">{{ now()->toDateString() }}</p>
        @endif
    </div>
</div>
BLADE;
    }

    private function view1up(): string
    {
        return <<<BLADE
{{-- Copilot prompt:
1-up page: single image, centered, full flex.
--}}
<div class="page">
    <div class="slot">
        <img src="{{ \$photos[0]->path }}" alt="{{ \$photos[0]->filename }}">
    </div>
</div>
BLADE;
    }

    private function view2up(): string
    {
        return <<<BLADE
{{-- Copilot prompt:
2-up page: two equal columns.
--}}
<div class="page">
@foreach(\$photos as \$p)
    <div class="slot">
        <img src="{{ \$p->path }}" alt="{{ \$p->filename }}">
    </div>
@endforeach
</div>
BLADE;
    }

    private function view3up(): string
    {
        return <<<BLADE
{{-- Copilot prompt:
3-up page: three equal columns.
--}}
<div class="page">
@foreach(\$photos as \$p)
    <div class="slot">
        <img src="{{ \$p->path }}" alt="{{ \$p->filename }}">
    </div>
@endforeach
</div>
BLADE;
    }
}
