<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class SidecarExtractor
{
    /** Ensure working directory exists and return its absolute path. */
    public function prepareWorkdir(string $folder): string
    {
        $root = storage_path('app/pdf-exports/_ml/' . sha1($folder));
        if (!is_dir($root)) @mkdir($root, 0775, true);
        if (!is_dir($root . '/images')) @mkdir($root . '/images', 0775, true);
        return $root;
    }

    /**
     * Download remote images to workdir/images and build a TSV list file: "localPath\tremotePath" per line.
     * @param array<int, \App\DTO\PhotoDto> $photos
     * @return array{list:string, downloaded:int, reused:int, errors:int}
     */
    public function downloadAndBuildList(array $photos, string $workdir, bool $force = false): array
    {
        $imagesDir = $workdir . DIRECTORY_SEPARATOR . 'images';
        $listFile = $workdir . DIRECTORY_SEPARATOR . 'list.tsv';
        $fh = fopen($listFile, 'w');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open list file for writing: ' . $listFile);
        }

        $disk = Storage::disk('nextcloud');
        $downloaded = 0; $reused = 0; $errors = 0;
        foreach ($photos as $p) {
            $ext = pathinfo($p->filename ?? basename($p->path), PATHINFO_EXTENSION);
            $name = sha1($p->path) . ($ext ? ('.' . strtolower($ext)) : '');
            $local = $imagesDir . DIRECTORY_SEPARATOR . $name;
            if ($force || !is_file($local) || filesize($local) <= 0) {
                try {
                    $stream = $disk->readStream($p->path);
                    if ($stream) {
                        $out = fopen($local, 'w');
                        if ($out) {
                            stream_copy_to_stream($stream, $out);
                            fclose($out);
                        }
                        if (is_resource($stream)) @fclose($stream);
                        if (!is_file($local) || filesize($local) <= 0) {
                            @unlink($local);
                            $errors++;
                            continue;
                        }
                        $downloaded++;
                    } else {
                        $errors++;
                        continue;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    @unlink($local);
                    continue;
                }
            } else {
                $reused++;
            }
            fwrite($fh, $local . "\t" . $p->path . "\n");
        }
        fclose($fh);

        return ['list' => $listFile, 'downloaded' => $downloaded, 'reused' => $reused, 'errors' => $errors];
    }

    /** Run the Python sidecar and write JSONL output to $outFile. Returns exit code. */
    public function runSidecar(string $listFile, string $outFile, ?int $timeoutSeconds = 600): int
    {
        $cmd = (string) config('photobook.ml.sidecar', 'python ml_extract.py');

        // Use shell to respect the configured command string; set CWD to project root.
        $proc = Process::fromShellCommandline(
            $cmd . ' --list ' . $this->esc($listFile) . ' --out ' . $this->esc($outFile),
            base_path(),
            null,
            null,
            $timeoutSeconds
        );
        $proc->run(function ($type, $buffer) {
            if ($type === Process::OUT) { \Log::debug('Sidecar:out ' . trim($buffer)); }
            else { \Log::debug('Sidecar:err ' . trim($buffer)); }
        });
        return $proc->getExitCode() ?? 1;
    }

    /** Import JSONL file into photo_features table using FeatureRepository. */
    public function importJsonl(string $jsonlPath, FeatureRepository $repo): array
    {
        $imported = 0; $errors = 0;
        $fh = @fopen($jsonlPath, 'r');
        if ($fh === false) return ['imported' => 0, 'errors' => 1];
        while (!feof($fh)) {
            $line = fgets($fh);
            if ($line === false) break;
            $line = trim($line);
            if ($line === '') continue;
            $obj = json_decode($line, true);
            if (!is_array($obj) || empty($obj['path'])) { $errors++; continue; }
            if (!empty($obj['error'])) { $errors++; continue; }
            $data = [];
            if (array_key_exists('faces', $obj)) $data['faces'] = $obj['faces'];
            if (array_key_exists('saliency', $obj)) $data['saliency'] = $obj['saliency'];
            if (array_key_exists('aesthetic', $obj)) $data['aesthetic'] = $obj['aesthetic'];
            if (array_key_exists('horizon_deg', $obj)) $data['horizon_deg'] = $obj['horizon_deg'];
            try {
                if (!empty($data)) {
                    $repo->upsert((string) $obj['path'], $data);
                    $imported++;
                }
            } catch (\Throwable $e) {
                $errors++;
            }
        }
        fclose($fh);
        return ['imported' => $imported, 'errors' => $errors];
    }

    private function esc(string $path): string
    {
        // cross-platform simple quoting for shell
        if (str_contains($path, ' ')) {
            return '"' . str_replace('"', '\\"', $path) . '"';
        }
        return $path;
    }
}
