<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

class VideoAdPipelineService
{
    public function __construct(
        private readonly FirestoreJobService $jobService,
        private readonly OpenAIAdSpecService $specService,
        private readonly FluxImageService $fluxImageService,
        private readonly OpenAIVoiceoverService $voiceoverService,
        private readonly RemotionRenderService $remotionRenderService,
        private readonly FirebaseStorageService $storageService,
    ) {
    }

    public function processJob(string $jobId): void
    {
        $job = $this->jobService->getJob($jobId);
        if (!$job) {
            throw new RuntimeException('Video job not found');
        }

        $uid = trim((string) ($job['userId'] ?? ''));
        if ($uid === '') {
            throw new RuntimeException('Job owner is missing');
        }

        if (($job['status'] ?? '') === 'success') {
            return;
        }

        $workDir = sys_get_temp_dir().'/scrollstop-video-'.$jobId;
        $completedSuccessfully = false;
        $pipelineStartedAt = microtime(true);
        $stageTimingsMs = [];
        $keepWorkDirOnError = (bool) config('video.keep_workdir_on_error', false);
        $keepWorkDirOnSuccess = (bool) config('video.keep_workdir_on_success', false);
        $skipStorageUpload = (bool) config('video.skip_storage_upload', false);
        $debugOutput = [
            'workDir' => $workDir,
            'steps' => [],
        ];
        $measure = function (string $stage, callable $callback) use (&$stageTimingsMs, $jobId) {
            $startedAt = microtime(true);
            try {
                return $callback();
            } finally {
                $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
                $stageTimingsMs[$stage] = $elapsedMs;
                Log::info('Video job stage completed', [
                    'jobId' => $jobId,
                    'stage' => $stage,
                    'durationMs' => $elapsedMs,
                ]);
            }
        };

        try {
            Log::info('Video job processing started', [
                'jobId' => $jobId,
                'uid' => $uid,
                'skipStorageUpload' => $skipStorageUpload,
                'staticMode' => (bool) config('video.static_mode', false),
            ]);

            $this->jobService->markProcessing($jobId);

            $inputPayload = is_array($job['inputPayload'] ?? null) ? $job['inputPayload'] : [];
            $isStaticMode = (bool) config('video.static_mode', false);
            $debugOutput['mode'] = $isStaticMode ? 'static' : 'dynamic';

            if ($isStaticMode) {
                $spec = $measure('build_static_spec', fn () => $this->buildStaticRenderSpec($inputPayload));
                $scenes = is_array($spec['scenes'] ?? null) ? $spec['scenes'] : [];
                $debugOutput['steps'][] = 'build_static_spec';
                $staticAudioPath = trim((string) config('video.static_audio_path', ''));
                if ($staticAudioPath !== '') {
                    $resolvedAudioPath = $this->resolveStaticFilePath($staticAudioPath);
                    if (is_file($resolvedAudioPath)) {
                        $spec['voiceoverAudioPath'] = $resolvedAudioPath;
                        $debugOutput['steps'][] = 'attach_static_audio';
                    }
                }
            } else {
                $spec = $measure('openai_spec', fn () => $this->specService->buildRenderSpec($inputPayload));
                $debugOutput['steps'][] = 'openai_spec';
                $referenceImages = is_array($inputPayload['referenceImages'] ?? null)
                    ? $inputPayload['referenceImages']
                    : [];

                $scenes = $measure('flux_images', fn () => $this->fluxImageService->generateSceneImages(
                    is_array($spec['scenes'] ?? null) ? $spec['scenes'] : [],
                    $workDir,
                    (string) ($spec['aspectRatio'] ?? '9:16'),
                    $referenceImages
                ));
                $debugOutput['steps'][] = 'flux_images';

                $spec['scenes'] = $scenes;

                $voiceAudioPath = $measure('openai_tts', fn () => $this->voiceoverService->maybeGenerate($spec, $workDir));
                if (is_string($voiceAudioPath) && $voiceAudioPath !== '') {
                    $spec['voiceoverAudioPath'] = $voiceAudioPath;
                    $debugOutput['steps'][] = 'openai_tts';

                    $voiceDurationSeconds = $this->probeAudioDurationSeconds($voiceAudioPath);
                    if ($voiceDurationSeconds !== null) {
                        $debugOutput['voiceoverDurationSeconds'] = $voiceDurationSeconds;
                    }
                }
            }

            $debugOutput['specSummary'] = $this->buildSpecSummary($spec);
            $debugOutput['scenes'] = $this->buildSceneAssetSummary($scenes);
            if (is_string($spec['voiceoverAudioPath'] ?? null) && $spec['voiceoverAudioPath'] !== '') {
                $debugOutput['voiceoverAudioPath'] = (string) $spec['voiceoverAudioPath'];
            }

            $outputPath = rtrim($workDir, '/').'/final.mp4';
            $remotionStats = $measure('remotion_render', fn () => $this->remotionRenderService->render($spec, $workDir, $outputPath));
            $debugOutput['steps'][] = 'remotion_render';
            $debugOutput['renderedVideoPath'] = $outputPath;
            if (is_file($outputPath)) {
                $debugOutput['renderedVideoBytes'] = filesize($outputPath);
            }
            if (is_array($remotionStats) && $remotionStats !== []) {
                $debugOutput['remotionStats'] = $remotionStats;
            }

            $outputPayload = [
                'durationSeconds' => (int) ($spec['durationSeconds'] ?? ($inputPayload['durationSeconds'] ?? 15)),
                'fps' => (int) ($spec['fps'] ?? 30),
                'sceneCount' => count($scenes),
                'mode' => $isStaticMode ? 'static' : 'dynamic',
                'debug' => $debugOutput,
            ];

            $upload = null;
            if (!$skipStorageUpload) {
                $upload = $measure('storage_upload', fn () => $this->storageService->uploadVideo($outputPath, $uid, $jobId));
            }

            $stageTimingsMs['total'] = (int) round((microtime(true) - $pipelineStartedAt) * 1000);
            $debugOutput['timingsMs'] = $stageTimingsMs;
            $outputPayload['debug'] = $debugOutput;
            $outputPayload['timingsMs'] = $stageTimingsMs;

            if ($skipStorageUpload) {
                $this->jobService->markSuccess($jobId, '', array_merge($outputPayload, [
                    'storageSkipped' => true,
                    'storageSkipReason' => 'VIDEO_SKIP_STORAGE_UPLOAD=true',
                ]));
            } else {
                $upload = $upload ?? [];
                $this->jobService->markSuccess($jobId, $upload['url'] ?? '', array_merge($outputPayload, [
                    'storagePath' => $upload['path'] ?? null,
                    'urlExpiresAt' => $upload['urlExpiresAt'] ?? null,
                    'storageBucket' => $upload['bucket'] ?? null,
                ]));
            }

            Log::info('Video job processing completed', [
                'jobId' => $jobId,
                'uid' => $uid,
                'mode' => $debugOutput['mode'] ?? null,
                'sceneCount' => count($scenes),
                'timingsMs' => $stageTimingsMs,
            ]);
            $completedSuccessfully = true;
        } catch (\Throwable $exception) {
            $safeMessage = $this->sanitizeError($exception->getMessage());
            $stageTimingsMs['total'] = (int) round((microtime(true) - $pipelineStartedAt) * 1000);
            if ($keepWorkDirOnError) {
                $safeMessage .= ' [debugDir: '.$workDir.']';
                $debugOutput['debugDir'] = $workDir;
            }
            $debugOutput['timingsMs'] = $stageTimingsMs;
            try {
                $this->jobService->markError($jobId, $safeMessage, [
                    'mode' => $debugOutput['mode'] ?? null,
                    'steps' => $debugOutput['steps'] ?? [],
                    'timingsMs' => $stageTimingsMs,
                    'specSummary' => $debugOutput['specSummary'] ?? [],
                    'scenes' => $debugOutput['scenes'] ?? [],
                    'voiceoverAudioPath' => $debugOutput['voiceoverAudioPath'] ?? null,
                    'renderedVideoPath' => $debugOutput['renderedVideoPath'] ?? null,
                    'renderedVideoBytes' => $debugOutput['renderedVideoBytes'] ?? null,
                    'workDir' => $debugOutput['workDir'] ?? null,
                    'debugDir' => $debugOutput['debugDir'] ?? null,
                ]);
            } catch (\Throwable $updateError) {
                Log::warning('Failed to mark video job as error', [
                    'jobId' => $jobId,
                ]);
            }

            Log::warning('Video job processing failed', [
                'jobId' => $jobId,
                'uid' => $uid,
                'error' => $safeMessage,
                'timingsMs' => $stageTimingsMs,
            ]);

            throw $exception;
        } finally {
            $preserveSuccessArtifacts = $keepWorkDirOnSuccess || $skipStorageUpload;
            if (($completedSuccessfully && !$preserveSuccessArtifacts) || (!$completedSuccessfully && !$keepWorkDirOnError)) {
                $this->cleanupDirectory($workDir);
            }
        }
    }

    private function sanitizeError(string $message): string
    {
        $safe = trim($message);
        if ($safe === '') {
            return 'Video generation failed';
        }

        $safe = preg_replace('/sk-[A-Za-z0-9_\-]+/', '[redacted]', $safe) ?: $safe;

        return mb_substr($safe, 0, 500);
    }

    private function buildStaticRenderSpec(array $inputPayload): array
    {
        $staticImagePath = trim((string) config('video.static_image_path', ''));
        if ($staticImagePath === '') {
            throw new RuntimeException('VIDEO_STATIC_IMAGE_PATH missing');
        }

        $resolvedImagePath = $this->resolveStaticFilePath($staticImagePath);
        if (!is_file($resolvedImagePath)) {
            throw new RuntimeException('Static image not found: '.$resolvedImagePath);
        }

        $durationSeconds = (int) ($inputPayload['durationSeconds'] ?? 15);
        $durationSeconds = max(10, min(20, $durationSeconds));

        $productName = trim((string) ($inputPayload['productName'] ?? 'Product'));
        $description = trim((string) ($inputPayload['productDescription'] ?? ''));
        $platform = trim((string) ($inputPayload['platform'] ?? 'Instagram'));
        $tone = trim((string) ($inputPayload['tone'] ?? 'Professional'));
        $language = trim((string) ($inputPayload['language'] ?? 'Turkish'));
        $cta = trim((string) ($inputPayload['cta'] ?? ''));
        $includePrice = (bool) ($inputPayload['includePrice'] ?? false);
        $priceText = trim((string) ($inputPayload['priceText'] ?? ''));

        $defaultCta = strtolower($language) === 'turkish'
            ? 'Hemen satin al'
            : 'Shop now';

        $sceneTexts = [];
        $sceneTexts[] = $productName !== '' ? $productName : 'Product';

        $middleText = $description !== ''
            ? mb_substr($description, 0, 80)
            : (strtolower($language) === 'turkish'
                ? 'Modern tasarim ve gunluk kullanim icin ideal'
                : 'Modern design for everyday use');

        if ($includePrice && $priceText !== '') {
            $middleText .= "\n".$priceText;
        }

        $sceneTexts[] = trim($middleText);
        $sceneTexts[] = trim(($cta !== '' ? $cta : $defaultCta)."\n".$platform);

        $durations = $this->splitDuration($durationSeconds, count($sceneTexts));
        $scenes = [];

        foreach ($sceneTexts as $index => $text) {
            $scenes[] = [
                'durationSeconds' => $durations[$index] ?? 3,
                'imagePrompt' => 'static-mode',
                'overlayText' => $text,
                'textAnimations' => ['fade-in'],
                'transition' => 'cut',
                'imagePath' => $resolvedImagePath,
            ];
        }

        return [
            'durationSeconds' => $durationSeconds,
            'fps' => max(24, min(60, (int) config('video.default_fps', 24))),
            'format' => 'mp4',
            'aspectRatio' => trim((string) ($inputPayload['aspectRatio'] ?? '9:16')),
            'tone' => $tone !== '' ? $tone : 'Professional',
            'platform' => $platform !== '' ? $platform : 'Instagram',
            'language' => $language !== '' ? $language : 'Turkish',
            'scenes' => $scenes,
            'voiceover' => [
                'enabled' => false,
                'script' => '',
                'gender' => 'female',
                'style' => 'friendly',
            ],
        ];
    }

    private function splitDuration(int $durationSeconds, int $sceneCount): array
    {
        $sceneCount = max(1, $sceneCount);
        $base = intdiv($durationSeconds, $sceneCount);
        $base = max(1, $base);

        $durations = array_fill(0, $sceneCount, $base);
        $used = $base * $sceneCount;
        $remaining = $durationSeconds - $used;

        $index = 0;
        while ($remaining > 0) {
            $durations[$index % $sceneCount] += 1;
            $remaining -= 1;
            $index += 1;
        }

        return $durations;
    }

    private function probeAudioDurationSeconds(string $audioPath): ?float
    {
        if (!is_file($audioPath)) {
            return null;
        }

        $process = new Process([
            'ffprobe',
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $audioPath,
        ]);
        $process->setTimeout(12);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $value = trim($process->getOutput());
        if ($value === '') {
            return null;
        }

        $duration = (float) $value;
        if ($duration <= 0) {
            return null;
        }

        return round($duration, 3);
    }

    private function resolveStaticFilePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return '';
        }

        if (str_starts_with($trimmed, '/')) {
            return $trimmed;
        }

        return base_path($trimmed);
    }

    private function buildSpecSummary(array $spec): array
    {
        $scenes = is_array($spec['scenes'] ?? null) ? $spec['scenes'] : [];
        $voiceover = is_array($spec['voiceover'] ?? null) ? $spec['voiceover'] : [];

        return [
            'durationSeconds' => (int) ($spec['durationSeconds'] ?? 0),
            'fps' => (int) ($spec['fps'] ?? 30),
            'aspectRatio' => (string) ($spec['aspectRatio'] ?? '9:16'),
            'platform' => (string) ($spec['platform'] ?? ''),
            'language' => (string) ($spec['language'] ?? ''),
            'sceneCount' => count($scenes),
            'voiceoverEnabled' => (bool) ($voiceover['enabled'] ?? false),
            'voiceoverScriptPreview' => $this->shorten((string) ($voiceover['script'] ?? ''), 240),
        ];
    }

    private function buildSceneAssetSummary(array $scenes): array
    {
        $result = [];

        foreach ($scenes as $index => $scene) {
            if (!is_array($scene)) {
                continue;
            }

            $result[] = [
                'index' => (int) $index,
                'durationSeconds' => (int) ($scene['durationSeconds'] ?? 0),
                'imagePrompt' => $this->shorten((string) ($scene['imagePrompt'] ?? ''), 220),
                'overlayText' => $this->shorten((string) ($scene['overlayText'] ?? ''), 220),
                'imagePath' => (string) ($scene['imagePath'] ?? ''),
                'referenceImageIndex' => (int) ($scene['referenceImageIndex'] ?? 0),
            ];
        }

        return $result;
    }

    private function shorten(string $value, int $limit): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $value) ?: '');
        if ($normalized === '') {
            return '';
        }

        if (mb_strlen($normalized) <= $limit) {
            return $normalized;
        }

        return mb_substr($normalized, 0, max(1, $limit - 3)).'...';
    }

    private function cleanupDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->cleanupDirectory($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
