<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use App\Jobs\BuildPhotoBook;
use App\Services\{NextcloudPhotoRepository, ImageProbe, LayoutPlanner, PhotoBookBuilder, PdfRenderer, PageGrouper, LayoutPlannerV2};

class PhotobookBuildNow extends Command
{
    protected $signature = 'photobook:build-now
        {--folder= : Nextcloud folder path}
        {--paper= : Paper size (a4, a3)}
        {--orientation= : Paper orientation (landscape, portrait)}
        {--dpi= : PDF DPI setting}
        {--force-refresh : Clear cache and re-download images}
        {--print-mode : Enable print-ready mode with bleed and crop marks}';

    protected $description = 'Build the photobook synchronously (without queue)';

    public function handle(
        NextcloudPhotoRepository $repo,
        ImageProbe $probe,
        LayoutPlanner $planner,
        PageGrouper $grouper,
        LayoutPlannerV2 $plannerV2,
        PhotoBookBuilder $builder,
        PdfRenderer $pdf
    ) {
        $opts = [
            'folder' => $this->option('folder') ?: Config::get('photobook.folder'),
            'paper' => $this->option('paper') ?: Config::get('photobook.paper'),
            'orientation' => $this->option('orientation') ?: Config::get('photobook.orientation', 'landscape'),
            'dpi' => (int) ($this->option('dpi') ?: Config::get('photobook.dpi')),
            'force_refresh' => (bool) $this->option('force-refresh'),
            'print_mode' => (bool) $this->option('print-mode') || Config::get('photobook.print.enabled', false),
        ];

        $this->info('Building photobook for folder: ' . $opts['folder']);

        if ($opts['print_mode']) {
            $printConfig = Config::get('photobook.print', []);
            $this->info('Print mode enabled:');
            $this->line('  - Bleed: ' . ($printConfig['bleed_mm'] ?? 3.0) . 'mm');
            $this->line('  - Crop marks: ' . (($printConfig['crop_marks'] ?? true) ? 'Yes' : 'No'));
            $this->line('  - Spine margin: ' . ($printConfig['spine_margin_mm'] ?? 10.0) . 'mm');
        }

        $job = new BuildPhotoBook($opts);
        $job->handle($repo, $probe, $planner, $grouper, $plannerV2, $builder, $pdf);

        $this->info('Done. Check storage/app/pdf-exports for the latest PDF.');

        if ($opts['print_mode']) {
            $this->newLine();
            $this->warn('Remember to convert to CMYK before sending to print shop:');
            $this->line('  ./scripts/convert-to-cmyk.sh storage/app/pdf-exports/book-*.pdf');
        }

        return 0;
    }
}
