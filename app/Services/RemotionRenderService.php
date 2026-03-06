<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class RemotionRenderService
{
    public function render(array $renderSpec, string $workDir, string $outputPath): array
    {
        if (!is_dir($workDir) && !mkdir($workDir, 0775, true) && !is_dir($workDir)) {
            throw new RuntimeException('Unable to create temporary render directory');
        }

        $specPath = rtrim($workDir, '/').'/render-spec.json';
        $json = json_encode($renderSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($specPath, $json) === false) {
            throw new RuntimeException('Unable to write render spec file');
        }

        $rendererScript = base_path('remotion-renderer/render.mjs');
        if (!is_file($rendererScript)) {
            throw new RuntimeException('Remotion renderer script not found');
        }

        $bundlerPackagePath = base_path('remotion-renderer/node_modules/@remotion/bundler/package.json');
        if (!is_file($bundlerPackagePath)) {
            throw new RuntimeException(
                'Remotion dependencies missing. Run: cd remotion-renderer && npm install'
            );
        }

        $nodeBinary = trim((string) config('video.node_binary', 'node'));
        $crf = max(0, (int) config('video.remotion_crf', 23));
        $x264Preset = trim((string) config('video.remotion_x264_preset', 'veryfast'));
        $concurrency = (int) config('video.remotion_concurrency', 0);
        $scale = (float) config('video.remotion_scale', 1.0);
        $bundleCacheDir = trim((string) config('video.remotion_bundle_cache_dir', '/tmp/scrollstop-remotion-bundles'));
        $statsPath = rtrim($workDir, '/').'/remotion-stats.json';

        $command = [
            $nodeBinary,
            $rendererScript,
            '--spec',
            $specPath,
            '--out',
            $outputPath,
            '--crf',
            (string) $crf,
            '--stats-out',
            $statsPath,
        ];

        if ($x264Preset !== '') {
            $command[] = '--x264-preset';
            $command[] = $x264Preset;
        }

        if ($concurrency > 0) {
            $command[] = '--concurrency';
            $command[] = (string) $concurrency;
        }

        if ($scale > 0 && $scale !== 1.0) {
            $command[] = '--scale';
            $command[] = (string) $scale;
        }

        if ($bundleCacheDir !== '') {
            $command[] = '--bundle-cache-dir';
            $command[] = $bundleCacheDir;
        }

        $process = new Process($command, base_path());

        $timeoutSeconds = max(60, (int) config('video.remotion_timeout_seconds', 600));
        $process->setTimeout($timeoutSeconds);
        $process->run();

        if (!$process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());
            $stdout = trim($process->getOutput());
            $messageRaw = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'Remotion render failed');
            $message = mb_substr($messageRaw, 0, 500);
            throw new RuntimeException($message);
        }

        if (!is_file($outputPath) || filesize($outputPath) === 0) {
            throw new RuntimeException('Rendered video output missing');
        }

        if (!is_file($statsPath)) {
            return [];
        }

        $statsRaw = file_get_contents($statsPath);
        if ($statsRaw === false || trim($statsRaw) === '') {
            return [];
        }

        $stats = json_decode($statsRaw, true);
        return is_array($stats) ? $stats : [];
    }
}
