<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

class PlaywrightPdfRenderer
{
    /**
     * Render a photobook to PDF via Playwright.
     *
     * @param  string $previewUrl  Full URL to the print-ready preview page
     * @param  string $outputPath  Absolute path where the PDF should be written
     * @param  array  $options     Optional: width (mm), height (mm), timeout (s)
     * @throws \RuntimeException   On Playwright failure
     */
    public function render(string $previewUrl, string $outputPath, array $options = []): void
    {
        $widthMm  = $options['width']   ?? 210;
        $heightMm = $options['height']  ?? 297;
        $timeout  = $options['timeout'] ?? 180;

        $renderScript = base_path('playwright/render.js');

        if (!is_file($renderScript)) {
            throw new \RuntimeException("Playwright render script not found: {$renderScript}");
        }

        [$previewUrl, $previewServer] = $this->preparePreviewUrl($previewUrl);

        try {
            $cmd = [
                'node',
                $renderScript,
                '--url',    $previewUrl,
                '--output', $outputPath,
                '--width',  (string) $widthMm,
                '--height', (string) $heightMm,
            ];

            Log::info('PlaywrightPdfRenderer: starting', [
                'url'              => $previewUrl,
                'output'           => $outputPath,
                'size'             => "{$widthMm}×{$heightMm}mm",
                'uses_preview_app' => $previewServer !== null,
            ]);

            $result = Process::timeout($timeout)->run($cmd);

            if (!$result->successful()) {
                $stderr = $result->errorOutput();

                Log::error('PlaywrightPdfRenderer: failed', [
                    'exitCode' => $result->exitCode(),
                    'stderr'   => $stderr,
                    'stdout'   => $result->output(),
                ]);

                if (str_contains($stderr, 'Executable doesn\'t exist') || str_contains($stderr, 'npx playwright install')) {
                    throw new \RuntimeException('Playwright Chromium is not installed on the server. Run `npx playwright install chromium` and retry.');
                }

                throw new \RuntimeException(
                    'Playwright PDF render failed (exit ' . $result->exitCode() . '): ' . $stderr
                );
            }

            if (!is_file($outputPath)) {
                throw new \RuntimeException("Playwright completed but output file not found: {$outputPath}");
            }

            Log::info('PlaywrightPdfRenderer: done', [
                'output' => $outputPath,
                'size_kb' => round(filesize($outputPath) / 1024, 1),
            ]);
        } finally {
            if ($previewServer) {
                $previewServer->stop(2);
            }
        }
    }

    /**
     * Convenience: build the preview URL for a given folder hash.
     */
    public static function previewUrl(string $hash, array $query = []): string
    {
        $q = array_merge(['print' => '1'], $query);
        return url('/photobook/preview/' . $hash) . '?' . http_build_query($q);
    }

    /**
     * The PHP built-in server only handles one request at a time.
     * During export the current request is still open, so Playwright cannot
     * call back into the same server for /photobook/preview/... without
     * deadlocking. In that case we boot a short-lived second server.
     *
     * @return array{0:string,1:?SymfonyProcess}
     */
    private function preparePreviewUrl(string $previewUrl): array
    {
        if (PHP_SAPI !== 'cli-server') {
            return [$previewUrl, null];
        }

        $port = $this->reserveLoopbackPort();
        $baseUrl = "http://127.0.0.1:{$port}";
        $server = new SymfonyProcess(
            ['php', 'artisan', 'serve', '--host=127.0.0.1', "--port={$port}"],
            base_path(),
            ['APP_URL' => $baseUrl]
        );

        $server->start();
        $this->waitForPreviewServer($server, $baseUrl);

        $rewrittenUrl = preg_replace('#^https?://[^/]+#', $baseUrl, $previewUrl, 1) ?? $previewUrl;

        return [$rewrittenUrl, $server];
    }

    private function reserveLoopbackPort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!$socket) {
            throw new \RuntimeException("Unable to reserve a local port for PDF preview: {$errstr}");
        }

        $name = stream_socket_get_name($socket, false) ?: '';
        fclose($socket);

        $parts = explode(':', $name);
        $port = (int) end($parts);

        if ($port <= 0) {
            throw new \RuntimeException('Unable to determine a local port for PDF preview.');
        }

        return $port;
    }

    private function waitForPreviewServer(SymfonyProcess $server, string $baseUrl): void
    {
        $deadline = microtime(true) + 15;
        $probeUrl = $baseUrl . '/';

        while (microtime(true) < $deadline) {
            if (!$server->isRunning()) {
                $stderr = trim($server->getErrorOutput());
                $stdout = trim($server->getOutput());
                $detail = $stderr !== '' ? $stderr : $stdout;
                throw new \RuntimeException('Temporary preview server failed to start: ' . $detail);
            }

            if ($this->urlResponds($probeUrl)) {
                return;
            }

            usleep(200_000);
        }

        throw new \RuntimeException('Temporary preview server did not become ready in time.');
    }

    private function urlResponds(string $url): bool
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 1,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false && empty($http_response_header)) {
            return false;
        }

        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('#HTTP/\S+\s+(\d{3})#', $statusLine, $m)) {
            $status = (int) $m[1];
            return $status >= 200 && $status < 500;
        }

        return true;
    }
}
