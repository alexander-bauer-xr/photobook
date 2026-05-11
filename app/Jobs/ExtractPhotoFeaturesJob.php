<?php

namespace App\Jobs;

use App\Services\FeatureRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class ExtractPhotoFeaturesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 2;

    public function __construct(
        private string $nextcloudPath,
        private string $localImagePath,
    ) {}

    public function handle(FeatureRepository $repo): void
    {
        $outputPath = tempnam(sys_get_temp_dir(), 'pbf') . '.json';

        try {
            $result = Process::timeout(60)->run([
                'python3', '-m', 'photobook_ai.cli',
                'analyze',
                '--input',  $this->localImagePath,
                '--output', $outputPath,
            ]);

            if (!$result->successful()) {
                Log::warning('ExtractPhotoFeaturesJob: python failed', [
                    'path'   => $this->nextcloudPath,
                    'stderr' => $result->errorOutput(),
                ]);
                return;
            }

            if (!is_file($outputPath)) {
                Log::warning('ExtractPhotoFeaturesJob: no output file', [
                    'path' => $this->nextcloudPath,
                ]);
                return;
            }

            $raw = json_decode(file_get_contents($outputPath), true);

            if (!is_array($raw)) {
                Log::warning('ExtractPhotoFeaturesJob: invalid JSON output', [
                    'path' => $this->nextcloudPath,
                ]);
                return;
            }

            $repo->upsert($this->nextcloudPath, [
                'phash'          => $raw['phash']          ?? null,
                'sharpness'      => $raw['quality']['sharpness'] ?? null,
                'aesthetic'      => $raw['quality']['aesthetic'] ?? null,
                'faces'          => $raw['faces']          ?? null,
                'saliency'       => $raw['saliency']       ?? null,
                'horizon_deg'    => $raw['horizon_deg']    ?? null,
                'suggested_crop' => $raw['suggested_crop'] ?? null,
            ], $raw['analysis_version'] ?? 'v2');

            Log::info('ExtractPhotoFeaturesJob: done', ['path' => $this->nextcloudPath]);
        } finally {
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
        }
    }
}
