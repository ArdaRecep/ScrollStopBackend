<?php

namespace App\Http\Controllers;

use App\Services\FirestoreJobService;
use App\Services\VideoJobDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class VideoController extends Controller
{
    public function create(Request $request, FirestoreJobService $jobService, VideoJobDispatcher $dispatcher): JsonResponse
    {
        $uid = trim((string) $request->attributes->get('firebase_uid', ''));
        if ($uid === '') {
            return response()->json(['ok' => false, 'error' => 'Invalid token'], 401);
        }

        $rateLimit = max(1, (int) config('video.post_rate_limit_per_minute', 5));
        $rateKey = 'video:create:'.$uid;

        if (RateLimiter::tooManyAttempts($rateKey, $rateLimit)) {
            return response()->json([
                'ok' => false,
                'error' => 'Too many video generation requests. Please wait and retry.',
            ], 429);
        }

        RateLimiter::hit($rateKey, 60);

        $validated = $request->validate([
            'productName' => 'required|string|max:200',
            'productDescription' => 'nullable|string|max:3000',
            'brandName' => 'nullable|string|max:200',
            'platform' => 'required|string|in:TikTok,Instagram,YouTube',
            'durationSeconds' => 'required|integer|min:10|max:20',
            'tone' => 'required|string|max:100',
            'language' => 'required|string|in:English,Turkish',
            'voice' => 'nullable|array',
            'voice.enabled' => 'nullable|boolean',
            'voice.gender' => 'nullable|string|in:male,female|required_if:voice.enabled,true',
            'voice.style' => 'nullable|string|in:serious,friendly,energetic|required_if:voice.enabled,true',
            'aspectRatio' => 'nullable|string|in:9:16',
            'includePrice' => 'nullable|boolean',
            'priceText' => 'nullable|string|max:120|required_if:includePrice,true',
            'cta' => 'nullable|string|max:160',
        ]);

        $inputPayload = $this->normalizeInputPayload($validated);
        $jobId = (string) Str::ulid();
        $jobCreated = false;

        try {
            $jobService->createVideoJob($jobId, $uid, $inputPayload);
            $jobCreated = true;
            $dispatcher->dispatch($jobId);
        } catch (\Throwable $exception) {
            if ($jobCreated) {
                try {
                    $jobService->markError($jobId, 'Unable to dispatch video job');
                } catch (\Throwable) {
                    // no-op
                }
            }

            return response()->json([
                'ok' => false,
                'error' => 'Unable to create video generation job right now.',
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'jobId' => $jobId,
            'status' => 'pending',
        ]);
    }

    public function status(Request $request, string $jobId, FirestoreJobService $jobService): JsonResponse
    {
        $uid = trim((string) $request->attributes->get('firebase_uid', ''));
        if ($uid === '') {
            return response()->json(['ok' => false, 'error' => 'Invalid token'], 401);
        }

        $job = $jobService->getJob($jobId);
        if (!$job) {
            return response()->json([
                'ok' => false,
                'error' => 'Video job not found',
            ], 404);
        }

        if ((string) ($job['userId'] ?? '') !== $uid) {
            return response()->json([
                'ok' => false,
                'error' => 'You do not have access to this video job',
            ], 403);
        }

        $status = (string) ($job['status'] ?? 'pending');
        $videoUrl = $status === 'success' ? (string) ($job['videoUrl'] ?? '') : null;
        $error = $status === 'error' ? (string) ($job['errorMessage'] ?? 'Video generation failed') : null;
        $outputPayload = is_array($job['outputPayload'] ?? null) ? $job['outputPayload'] : [];

        return response()->json([
            'ok' => true,
            'jobId' => (string) ($job['id'] ?? $jobId),
            'status' => $status,
            'videoUrl' => $videoUrl !== '' ? $videoUrl : null,
            'error' => $error,
            'output' => $outputPayload,
        ]);
    }

    public function recent(Request $request, FirestoreJobService $jobService): JsonResponse
    {
        $uid = trim((string) $request->attributes->get('firebase_uid', ''));
        if ($uid === '') {
            return response()->json(['ok' => false, 'error' => 'Invalid token'], 401);
        }

        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $items = $jobService->listUserVideoJobs($uid, (int) ($validated['limit'] ?? 20));
        $mapped = array_map(function (array $item): array {
            $status = (string) ($item['status'] ?? 'pending');

            return [
                'jobId' => (string) ($item['id'] ?? ''),
                'status' => $status,
                'videoUrl' => $status === 'success' ? (($item['videoUrl'] ?? null) ?: null) : null,
                'error' => $status === 'error' ? (($item['errorMessage'] ?? null) ?: null) : null,
                'createdAt' => (string) ($item['createdAt'] ?? ''),
                'updatedAt' => (string) ($item['updatedAt'] ?? ''),
                'inputPayload' => is_array($item['inputPayload'] ?? null) ? $item['inputPayload'] : [],
                'output' => is_array($item['outputPayload'] ?? null) ? $item['outputPayload'] : [],
            ];
        }, $items);

        return response()->json([
            'ok' => true,
            'items' => $mapped,
        ]);
    }

    private function normalizeInputPayload(array $validated): array
    {
        $voice = is_array($validated['voice'] ?? null) ? $validated['voice'] : [];

        return [
            'productName' => trim((string) ($validated['productName'] ?? '')),
            'productDescription' => trim((string) ($validated['productDescription'] ?? '')),
            'brandName' => trim((string) ($validated['brandName'] ?? '')),
            'platform' => trim((string) ($validated['platform'] ?? 'TikTok')),
            'durationSeconds' => (int) ($validated['durationSeconds'] ?? 15),
            'tone' => trim((string) ($validated['tone'] ?? '')),
            'language' => trim((string) ($validated['language'] ?? 'English')),
            'voice' => [
                'enabled' => (bool) ($voice['enabled'] ?? false),
                'gender' => trim((string) ($voice['gender'] ?? 'female')),
                'style' => trim((string) ($voice['style'] ?? 'friendly')),
            ],
            'aspectRatio' => trim((string) ($validated['aspectRatio'] ?? '9:16')),
            'includePrice' => (bool) ($validated['includePrice'] ?? false),
            'priceText' => trim((string) ($validated['priceText'] ?? '')),
            'cta' => trim((string) ($validated['cta'] ?? '')),
        ];
    }
}
