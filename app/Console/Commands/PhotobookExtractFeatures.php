<?php

namespace App\Console\Commands;

use App\Services\{NextcloudPhotoRepository, ImageProbe, SidecarExtractor, FeatureRepository};
use Illuminate\Console\Command;

class PhotobookExtractFeatures extends Command
{
    protected $signature = 'photobook:extract {folder?} {--force : Re-download images into sidecar cache}';
    protected $description = 'Download images for a folder, run Python sidecar (faces/saliency/aesthetic/horizon), and import to photo_features';

    public function handle(
        NextcloudPhotoRepository $repo,
        ImageProbe $probe,
        SidecarExtractor $sidecar,
        FeatureRepository $features
    ): int {
        if (!config('photobook.ml.enable')) {
            $this->warn('ML disabled (photobook.ml.enable=false). Nothing to do.');
            return self::SUCCESS;
        }
        if (!\Illuminate\Support\Facades\Schema::hasTable('photo_features')) {
            $this->error('Table photo_features missing. Run: php artisan migrate');
            return self::FAILURE;
        }
        $folder = $this->argument('folder') ?: config('photobook.folder');
        $force = (bool) $this->option('force');

        $this->info('Listing photos from Nextcloud…');
        $photos = $repo->listPhotos($folder);
        $this->line('Found: ' . count($photos));
        if (!$photos) return self::SUCCESS;

        $this->info('Probing dimensions/metadata (for takenAt/quality)…');
        $photos = $probe->fillDimensions($photos);
        // Stable order
        usort($photos, fn($a,$b) => strcmp($a->path, $b->path));

        $workdir = $sidecar->prepareWorkdir($folder);
        $this->line('Workdir: ' . $workdir);

        $this->info('Downloading into sidecar cache + building list.tsv…');
        $dl = $sidecar->downloadAndBuildList($photos, $workdir, $force);
        $this->line("downloaded={$dl['downloaded']} reused={$dl['reused']} errors={$dl['errors']}");

        $out = $workdir . DIRECTORY_SEPARATOR . 'features.jsonl';
        if (is_file($out)) @unlink($out);

        $this->info('Running sidecar: ' . config('photobook.ml.sidecar'));
        $code = $sidecar->runSidecar($dl['list'], $out, 1200);
        if ($code !== 0) {
            $this->error('Sidecar failed with exit code ' . $code);
            return self::FAILURE;
        }
        if (!is_file($out) || filesize($out) <= 0) {
            $this->error('Sidecar produced no output: ' . $out);
            return self::FAILURE;
        }

        $this->info('Importing JSONL into database…');
        $res = $sidecar->importJsonl($out, $features);
        $this->line('Imported: ' . $res['imported'] . ' errors=' . $res['errors']);

        $this->components->info('Done.');
        $this->line('You can now enable PHOTOBOOK_ML_FACES=1 and PHOTOBOOK_ML_SALIENCY=1 to use focal points.');
        return self::SUCCESS;
    }
}
