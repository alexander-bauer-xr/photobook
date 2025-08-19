<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use App\Jobs\BuildPhotoBook;
use App\Services\{NextcloudPhotoRepository, ImageProbe, LayoutPlanner, PhotoBookBuilder, PdfRenderer, PageGrouper, LayoutPlannerV2};

class PhotobookBuildNow extends Command
{
    protected $signature = 'photobook:build-now {--folder=} {--paper=} {--orientation=} {--dpi=} {--force-refresh}';
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
        ];
        $this->info('Building photobook for folder: ' . $opts['folder']);
        $job = new BuildPhotoBook($opts);
        $job->handle($repo, $probe, $planner, $grouper, $plannerV2, $builder, $pdf);
        $this->info('Done. Check storage/app/pdf-exports for the latest PDF.');
        return 0;
    }
}
