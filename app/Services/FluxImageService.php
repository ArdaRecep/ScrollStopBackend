<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\Pool;
use RuntimeException;

class FluxImageService
{
    public function generateSceneImages(array $scenes, string $workDir, string $aspectRatio): array
    {
        if (!is_dir($workDir) && !mkdir($workDir, 0775, true) && !is_dir($workDir)) {
            throw new RuntimeException('Unable to create temporary render directory');
        }

        $preparedScenes = [];
        $endpoint = trim((string) config('services.flux.endpoint', 'https://api.fluxapi.ai/api/v1/flux/kontext/generate'));

        foreach ($scenes as $index => $scene) {
            if (!is_array($scene)) {
                continue;
            }

            $prompt = trim((string) ($scene['imagePrompt'] ?? ''));
            if ($prompt === '') {
                throw new RuntimeException('Missing imagePrompt in render scene');
            }

            $targetPath = rtrim($workDir, '/').'/scene-'.($index + 1).'.png';
            $preparedScenes[(string) $index] = [
                'index' => (int) $index,
                'prompt' => $prompt,
                'targetPath' => $targetPath,
                'scene' => $scene,
            ];
        }

        if ($preparedScenes === []) {
            return [];
        }

        if ($this->isFluxApiAiEndpoint($endpoint)) {
            return $this->generateSceneImagesFluxApiAi(
                $preparedScenes,
                $aspectRatio,
                $endpoint
            );
        }

        $outputScenes = [];
        foreach ($preparedScenes as $prepared) {
            $this->generateImageToPath(
                $prepared['prompt'],
                $aspectRatio,
                $prepared['targetPath']
            );

            $scene = $prepared['scene'];
            $scene['imagePath'] = $prepared['targetPath'];
            $outputScenes[] = $scene;
        }

        return $outputScenes;
    }

    private function generateSceneImagesFluxApiAi(
        array $preparedScenes,
        string $aspectRatio,
        string $endpoint
    ): array {
        $apiKey = trim((string) config('services.flux.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('FLUXAI_API_KEY missing');
        }

        $model = trim((string) config('services.flux.model', 'flux-kontext-pro'));
        $timeout = max(15, (int) config('services.flux.timeout_seconds', 60));
        $pollEndpoint = trim((string) config('services.flux.poll_endpoint', ''));
        if ($pollEndpoint === '') {
            $pollEndpoint = $this->deriveFluxApiAiPollEndpoint();
        }

        $generateResponses = Http::pool(function (Pool $pool) use (
            $preparedScenes,
            $apiKey,
            $timeout,
            $endpoint,
            $aspectRatio,
            $model
        ) {
            $requests = [];
            foreach ($preparedScenes as $key => $scene) {
                $payload = $this->buildFluxApiAiPayload(
                    $scene['prompt'],
                    $aspectRatio,
                    $model
                );

                $requests[$key] = $pool
                    ->as($key)
                    ->timeout($timeout)
                    ->withHeaders([
                        'Authorization' => 'Bearer '.$apiKey,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ])
                    ->post($endpoint, $payload);
            }

            return $requests;
        });

        $pending = [];
        $imageUrls = [];

        foreach ($preparedScenes as $key => $scene) {
            $response = $generateResponses[$key] ?? null;
            if (!$response instanceof Response) {
                throw new RuntimeException(
                    sprintf('Flux generate response missing for scene %d', $scene['index'] + 1)
                );
            }

            if (!$response->ok()) {
                throw new RuntimeException($this->buildFluxErrorMessage(
                    sprintf('Flux image generation failed for scene %d', $scene['index'] + 1),
                    $response,
                    true
                ));
            }

            $payload = $response->json();
            if (!is_array($payload)) {
                throw new RuntimeException(
                    sprintf('Flux response invalid for scene %d', $scene['index'] + 1)
                );
            }

            $code = (int) ($payload['code'] ?? 0);
            if ($code !== 200) {
                $msg = trim((string) ($payload['msg'] ?? 'unknown error'));
                throw new RuntimeException(
                    sprintf(
                        'Flux image generation failed for scene %d: %s (code %d)',
                        $scene['index'] + 1,
                        $msg,
                        $code
                    )
                );
            }

            $imageUrl = $this->extractImageUrl($payload);
            if ($imageUrl !== null) {
                $imageUrls[$key] = $imageUrl;
                continue;
            }

            $taskId = $this->extractFluxApiTaskId($payload);
            if ($taskId === '') {
                throw new RuntimeException(
                    sprintf('Flux taskId missing for scene %d', $scene['index'] + 1)
                );
            }

            $pending[$key] = [
                'taskId' => $taskId,
                'sceneIndex' => $scene['index'],
                'lastSuccessFlag' => null,
                'lastErrorCode' => null,
                'lastErrorMessage' => null,
            ];
        }

        $delayMs = max(500, (int) config('services.flux.poll_delay_ms', 2000));
        $jobTimeoutSeconds = max(60, (int) config('services.flux.job_timeout_seconds', 600));
        $maxRounds = max(1, (int) ceil(($jobTimeoutSeconds * 1000) / $delayMs));

        for ($round = 0; $round < $maxRounds && $pending !== []; $round++) {
            $snapshot = $pending;

            $pollResponses = Http::pool(function (Pool $pool) use (
                $snapshot,
                $apiKey,
                $pollEndpoint
            ) {
                $requests = [];

                foreach ($snapshot as $key => $meta) {
                    $requests[$key] = $pool
                        ->as($key)
                        ->timeout(20)
                        ->withHeaders([
                            'Authorization' => 'Bearer '.$apiKey,
                            'Accept' => 'application/json',
                        ])
                        ->get($pollEndpoint, [
                            'taskId' => $meta['taskId'],
                        ]);
                }

                return $requests;
            });

            foreach ($snapshot as $key => $meta) {
                $response = $pollResponses[$key] ?? null;
                if (!$response instanceof Response) {
                    continue;
                }

                if (!$response->ok()) {
                    continue;
                }

                $payload = $response->json();
                if (!is_array($payload)) {
                    continue;
                }

                $code = (int) ($payload['code'] ?? 0);
                if ($code !== 200) {
                    $msg = trim((string) ($payload['msg'] ?? 'unknown error'));
                    throw new RuntimeException(
                        sprintf(
                            'Flux poll failed for scene %d: %s (code %d)',
                            $meta['sceneIndex'] + 1,
                            $msg,
                            $code
                        )
                    );
                }

                $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
                $pending[$key]['lastSuccessFlag'] = array_key_exists('successFlag', $data)
                    ? (int) $data['successFlag']
                    : null;
                $pending[$key]['lastErrorCode'] = array_key_exists('errorCode', $data)
                    ? (string) $data['errorCode']
                    : null;
                $pending[$key]['lastErrorMessage'] = trim((string) ($data['errorMessage'] ?? ''));

                $imageUrl = $this->extractImageUrl($payload);
                if ($imageUrl !== null) {
                    $imageUrls[$key] = $imageUrl;
                    unset($pending[$key]);
                    continue;
                }

                if ($pending[$key]['lastErrorMessage'] !== '') {
                    $suffix = $pending[$key]['lastErrorCode'] !== null && trim((string) $pending[$key]['lastErrorCode']) !== ''
                        ? sprintf(' (errorCode: %s)', $pending[$key]['lastErrorCode'])
                        : '';

                    throw new RuntimeException(
                        sprintf(
                            'Flux job failed for scene %d: %s%s',
                            $meta['sceneIndex'] + 1,
                            $pending[$key]['lastErrorMessage'],
                            $suffix
                        )
                    );
                }
            }

            if ($pending !== []) {
                usleep($delayMs * 1000);
            }
        }

        if ($pending !== []) {
            $firstPending = reset($pending) ?: [];
            $taskId = trim((string) ($firstPending['taskId'] ?? ''));
            $taskSuffix = $taskId !== '' ? " (taskId: {$taskId})" : '';

            throw new RuntimeException(
                'Flux job timeout while waiting for image result'.$taskSuffix
            );
        }

        $downloadResponses = Http::pool(function (Pool $pool) use ($preparedScenes, $imageUrls, $timeout) {
            $requests = [];

            foreach ($preparedScenes as $key => $scene) {
                $imageUrl = trim((string) ($imageUrls[$key] ?? ''));
                if ($imageUrl === '') {
                    continue;
                }

                $requests[$key] = $pool
                    ->as($key)
                    ->timeout($timeout)
                    ->withHeaders([
                        'Accept' => 'image/*',
                    ])
                    ->get($imageUrl);
            }

            return $requests;
        });

        $outputScenes = [];
        foreach ($preparedScenes as $key => $scene) {
            $imageUrl = trim((string) ($imageUrls[$key] ?? ''));
            if ($imageUrl === '') {
                throw new RuntimeException(
                    sprintf('Flux image URL missing for scene %d', $scene['index'] + 1)
                );
            }

            $download = $downloadResponses[$key] ?? null;
            if (!$download instanceof Response || !$download->ok()) {
                throw new RuntimeException(
                    sprintf('Unable to download Flux image output for scene %d', $scene['index'] + 1)
                );
            }

            $this->writeFile($scene['targetPath'], $download->body());

            $sceneData = $scene['scene'];
            $sceneData['imagePath'] = $scene['targetPath'];
            $outputScenes[] = $sceneData;
        }

        return $outputScenes;
    }

    private function generateImageToPath(string $prompt, string $aspectRatio, string $targetPath): void
    {
        $apiKey = trim((string) config('services.flux.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('FLUXAI_API_KEY missing');
        }

        $endpoint = trim((string) config('services.flux.endpoint', 'https://api.bfl.ai/v1/flux-pro-1.1'));
        if ($endpoint === '') {
            throw new RuntimeException('FLUXAI_API_ENDPOINT missing');
        }

        $model = trim((string) config('services.flux.model', 'flux-kontext-pro'));
        $timeout = max(15, (int) config('services.flux.timeout_seconds', 60));
        [$width, $height] = $this->resolveDimensions($aspectRatio);
        $isFluxApiAi = $this->isFluxApiAiEndpoint($endpoint);

        $payload = $isFluxApiAi
            ? $this->buildFluxApiAiPayload($prompt, $aspectRatio, $model)
            : $this->buildBflPayload($prompt, $aspectRatio, $model, $width, $height);

        $response = $this->requestWithAuthFallback(
            fn (array $headers): Response => Http::timeout($timeout)
                ->withHeaders(array_merge($headers, [
                    'Accept' => 'application/json,image/png,image/jpeg',
                    'Content-Type' => 'application/json',
                ]))
                ->post($endpoint, $payload),
            $apiKey,
            $isFluxApiAi
        );

        $contentType = strtolower((string) $response->header('Content-Type', ''));
        if ($response->ok() && str_contains($contentType, 'image/')) {
            $this->writeFile($targetPath, $response->body());
            return;
        }

        if (!$response->ok()) {
            throw new RuntimeException($this->buildFluxErrorMessage(
                'Flux image generation failed',
                $response,
                $isFluxApiAi
            ));
        }

        $payload = $response->json();
        if (!is_array($payload)) {
            throw new RuntimeException('Flux image response invalid');
        }

        if ($isFluxApiAi) {
            $code = (int) ($payload['code'] ?? 0);
            if ($code !== 200) {
                $msg = trim((string) ($payload['msg'] ?? 'unknown error'));
                throw new RuntimeException(
                    sprintf('Flux image generation failed: %s (code %d)', $msg, $code)
                );
            }
        }

        $imageBinary = $this->extractBinaryFromPayload($payload);
        if ($imageBinary !== null) {
            $this->writeFile($targetPath, $imageBinary);
            return;
        }

        $pollingUrl = trim((string) ($payload['polling_url'] ?? $payload['pollingUrl'] ?? ''));
        if ($pollingUrl !== '') {
            $imageUrl = $this->pollImageUrl($pollingUrl, $apiKey, true, $isFluxApiAi);
        } else {
            $imageUrl = $this->extractImageUrl($payload);
        }

        if ($imageUrl === null) {
            $jobId = $isFluxApiAi
                ? $this->extractFluxApiTaskId($payload)
                : trim((string) ($payload['id'] ?? $payload['job_id'] ?? ''));

            if ($jobId !== '') {
                $imageUrl = $this->pollImageUrl($jobId, $apiKey, false, $isFluxApiAi);
            }
        }

        if ($imageUrl === null) {
            throw new RuntimeException('Flux did not return an image');
        }

        $download = Http::timeout($timeout)->get($imageUrl);
        if (!$download->ok()) {
            throw new RuntimeException('Unable to download Flux image output');
        }

        $this->writeFile($targetPath, $download->body());
    }

    private function pollImageUrl(
        string $target,
        string $apiKey,
        bool $isDirectPollUrl,
        bool $isFluxApiAi
    ): ?string
    {
        $pollEndpoint = trim((string) config('services.flux.poll_endpoint', ''));
        $jobId = $target;

        if ($isDirectPollUrl) {
            $pollEndpoint = $target;
            $jobId = '';
        } elseif ($pollEndpoint === '') {
            if ($isFluxApiAi) {
                $pollEndpoint = $this->deriveFluxApiAiPollEndpoint();
            } elseif (str_contains((string) config('services.flux.endpoint', ''), 'api.bfl.ai')) {
                $pollEndpoint = 'https://api.bfl.ai/v1/get_result';
            } else {
                return null;
            }
        }

        $attempts = max(2, (int) config('services.flux.poll_attempts', 12));
        $delayMs = max(500, (int) config('services.flux.poll_delay_ms', 2000));
        $jobTimeoutSeconds = max(60, (int) config('services.flux.job_timeout_seconds', 600));

        if ($isFluxApiAi) {
            $minimumAttemptsForTimeout = (int) ceil(($jobTimeoutSeconds * 1000) / $delayMs);
            $attempts = max($attempts, $minimumAttemptsForTimeout);
        }

        $lastSuccessFlag = null;
        $lastErrorCode = null;
        $lastErrorMessage = null;

        for ($i = 0; $i < $attempts; $i++) {
            $query = $jobId !== ''
                ? ($isFluxApiAi ? ['taskId' => $jobId] : ['id' => $jobId])
                : [];

            $response = $this->requestWithAuthFallback(
                fn (array $headers): Response => Http::timeout(20)
                    ->withHeaders($headers)
                    ->get($pollEndpoint, $query),
                $apiKey,
                $isFluxApiAi
            );

            if (!$isFluxApiAi && !$response->ok() && $jobId !== '') {
                $response = $this->requestWithAuthFallback(
                    fn (array $headers): Response => Http::timeout(20)
                        ->withHeaders(array_merge($headers, [
                            'Content-Type' => 'application/json',
                        ]))
                        ->post($pollEndpoint, ['id' => $jobId]),
                    $apiKey,
                    $isFluxApiAi
                );
            }

            if (!$response->ok()) {
                usleep($delayMs * 1000);
                continue;
            }

            $payload = $response->json();
            if (!is_array($payload)) {
                usleep($delayMs * 1000);
                continue;
            }

            if ($isFluxApiAi) {
                $code = (int) ($payload['code'] ?? 0);
                if ($code !== 200) {
                    $msg = trim((string) ($payload['msg'] ?? 'unknown error'));
                    throw new RuntimeException(
                        sprintf('Flux job failed: %s (code %d)', $msg, $code)
                    );
                }

                $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
                $lastSuccessFlag = array_key_exists('successFlag', $data)
                    ? (int) $data['successFlag']
                    : null;
                $lastErrorCode = array_key_exists('errorCode', $data)
                    ? (string) $data['errorCode']
                    : null;
                $lastErrorMessage = trim((string) ($data['errorMessage'] ?? ''));

                $url = $this->extractImageUrl($payload);
                if ($url !== null) {
                    return $url;
                }

                $successFlag = (int) ($data['successFlag'] ?? -1);
                if ($successFlag === 1) {
                    $fallbackUrl = trim((string) ($data['response']['resultImageUrl'] ?? ''));
                    if ($fallbackUrl !== '') {
                        return $fallbackUrl;
                    }

                    throw new RuntimeException('Flux job completed but image URL missing');
                }

                if ($lastErrorMessage !== '') {
                    $errorSuffix = $lastErrorCode !== null && trim($lastErrorCode) !== ''
                        ? sprintf(' (errorCode: %s)', $lastErrorCode)
                        : '';
                    throw new RuntimeException('Flux job failed: '.$lastErrorMessage.$errorSuffix);
                }

                usleep($delayMs * 1000);
                continue;
            }

            $url = $this->extractImageUrl($payload);
            if ($url !== null) {
                return $url;
            }

            $status = strtolower((string) ($payload['status'] ?? $payload['state'] ?? ''));
            if (in_array($status, ['failed', 'error', 'cancelled'], true)) {
                throw new RuntimeException('Flux job failed');
            }

            usleep($delayMs * 1000);
        }

        if ($isFluxApiAi) {
            $taskSuffix = $jobId !== '' ? " (taskId: {$jobId})" : '';
            $statusSuffixParts = [];
            if ($lastSuccessFlag !== null) {
                $statusSuffixParts[] = 'successFlag='.$lastSuccessFlag;
            }
            if ($lastErrorCode !== null && trim($lastErrorCode) !== '') {
                $statusSuffixParts[] = 'errorCode='.$lastErrorCode;
            }
            if ($lastErrorMessage !== null && trim($lastErrorMessage) !== '') {
                $statusSuffixParts[] = 'errorMessage='.mb_substr($lastErrorMessage, 0, 180);
            }
            $statusSuffix = $statusSuffixParts !== []
                ? ' ['.implode(', ', $statusSuffixParts).']'
                : '';

            throw new RuntimeException(
                'Flux job timeout while waiting for image result'.$taskSuffix.$statusSuffix
            );
        }

        return null;
    }

    private function requestWithAuthFallback(
        callable $requestFactory,
        string $apiKey,
        bool $preferBearer = false
    ): Response
    {
        $headerSets = $preferBearer
            ? [
                ['Authorization' => 'Bearer '.$apiKey],
                ['Authorization' => 'Bearer '.$apiKey, 'x-api-key' => $apiKey],
                ['Authorization' => 'Bearer '.$apiKey, 'x-key' => $apiKey],
                ['x-api-key' => $apiKey],
                ['x-key' => $apiKey],
            ]
            : [
                ['x-key' => $apiKey],
                ['x-api-key' => $apiKey],
                ['Authorization' => 'Bearer '.$apiKey],
                ['x-key' => $apiKey, 'Authorization' => 'Bearer '.$apiKey],
                ['x-api-key' => $apiKey, 'Authorization' => 'Bearer '.$apiKey],
            ];

        $lastResponse = null;

        foreach ($headerSets as $headers) {
            /** @var Response $response */
            $response = $requestFactory($headers);
            $lastResponse = $response;

            if (!in_array($response->status(), [401, 403], true)) {
                return $response;
            }
        }

        return $lastResponse ?? $requestFactory([]);
    }

    private function buildFluxErrorMessage(string $prefix, Response $response, bool $isFluxApiAi): string
    {
        $body = trim((string) $response->body());
        $message = '';

        $json = $response->json();
        if (is_array($json)) {
            if ($isFluxApiAi) {
                $message = trim((string) ($json['msg'] ?? ''));
            } else {
                $detail = trim((string) ($json['detail'] ?? ''));
                $error = trim((string) ($json['error'] ?? $json['message'] ?? ''));
                $message = $detail !== '' ? $detail : $error;
            }
        }

        if ($message === '' && $body !== '') {
            $message = mb_substr($body, 0, 220);
        }

        if ($message === '') {
            return sprintf('%s (status %d)', $prefix, $response->status());
        }

        return sprintf('%s: %s (status %d)', $prefix, $message, $response->status());
    }

    private function isFluxApiAiEndpoint(string $endpoint): bool
    {
        return str_contains(strtolower($endpoint), 'api.fluxapi.ai');
    }

    private function buildFluxApiAiPayload(string $prompt, string $aspectRatio, string $model): array
    {
        return [
            'prompt' => $prompt,
            'enableTranslation' => (bool) config('services.flux.enable_translation', true),
            'aspectRatio' => $aspectRatio,
            'outputFormat' => trim((string) config('services.flux.output_format', 'png')),
            'promptUpsampling' => (bool) config('services.flux.prompt_upsampling', false),
            'model' => $model,
            'safetyTolerance' => (int) config('services.flux.safety_tolerance', 2),
        ];
    }

    private function buildBflPayload(
        string $prompt,
        string $aspectRatio,
        string $model,
        int $width,
        int $height
    ): array
    {
        $payload = [
            'prompt' => $prompt,
            'output_format' => 'png',
            'width' => $width,
            'height' => $height,
        ];

        if (!str_contains((string) config('services.flux.endpoint', ''), 'api.bfl.ai')) {
            $payload['model'] = $model;
            $payload['aspect_ratio'] = $aspectRatio;
        }

        return $payload;
    }

    private function deriveFluxApiAiPollEndpoint(): string
    {
        $endpoint = trim((string) config('services.flux.endpoint', ''));
        if (str_ends_with($endpoint, '/generate')) {
            return substr($endpoint, 0, -strlen('/generate')).'/record-info';
        }

        return 'https://api.fluxapi.ai/api/v1/flux/kontext/record-info';
    }

    private function extractFluxApiTaskId(array $payload): string
    {
        return trim((string) (
            $payload['data']['taskId'] ??
            $payload['taskId'] ??
            $payload['data']['id'] ??
            ''
        ));
    }

    private function resolveDimensions(string $aspectRatio): array
    {
        return match (trim($aspectRatio)) {
            '9:16' => [576, 1024],
            '1:1' => [1024, 1024],
            '16:9' => [1024, 576],
            default => [576, 1024],
        };
    }

    private function extractImageUrl(array $payload): ?string
    {
        $responseNode = $payload['data']['response'] ?? $payload['response'] ?? null;
        if (is_string($responseNode) && trim($responseNode) !== '') {
            $decodedResponseNode = json_decode($responseNode, true);
            if (is_array($decodedResponseNode)) {
                $responseNode = $decodedResponseNode;
            }
        }

        $candidates = [
            $payload['image_url'] ?? null,
            $payload['imageUrl'] ?? null,
            $payload['url'] ?? null,
            $payload['sample'] ?? null,
            $payload['result']['image_url'] ?? null,
            $payload['result']['url'] ?? null,
            $payload['result']['sample'] ?? null,
            $payload['data'][0]['url'] ?? null,
            $payload['data']['response']['resultImageUrl'] ?? null,
            $payload['data']['response']['originImageUrl'] ?? null,
            $payload['data']['resultImageUrl'] ?? null,
            $payload['data']['url'] ?? null,
            is_array($responseNode) ? ($responseNode['resultImageUrl'] ?? null) : null,
            is_array($responseNode) ? ($responseNode['originImageUrl'] ?? null) : null,
            is_array($responseNode) ? ($responseNode['url'] ?? null) : null,
        ];

        foreach ($candidates as $candidate) {
            $url = trim((string) $candidate);
            if ($url === '') {
                continue;
            }

            if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
                return $url;
            }

            if (str_starts_with($url, 'file://')) {
                return $url;
            }
        }

        return null;
    }

    private function extractBinaryFromPayload(array $payload): ?string
    {
        $candidates = [
            $payload['b64_json'] ?? null,
            $payload['image_base64'] ?? null,
            $payload['data'][0]['b64_json'] ?? null,
            $payload['result']['b64_json'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $decoded = base64_decode($candidate, true);
            if ($decoded !== false && $decoded !== '') {
                return $decoded;
            }
        }

        return null;
    }

    private function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create image directory');
        }

        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('Unable to write image file');
        }
    }
}
