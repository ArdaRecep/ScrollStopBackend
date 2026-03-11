<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAIAdSpecService
{
    public function buildRenderSpec(array $input): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if (trim($apiKey) === '') {
            throw new RuntimeException('OPENAI_API_KEY missing');
        }

        $model = (string) config('services.openai.model', 'gpt-4.1-mini');

        $durationSeconds = (int) ($input['durationSeconds'] ?? 15);
        $durationSeconds = max(10, min(20, $durationSeconds));
        $defaultFps = $this->resolveDefaultFps();
        $minScenes = max(2, (int) config('video.min_scenes', 3));
        $maxScenes = max($minScenes, (int) config('video.max_scenes', 4));
        $dynamicMinScenes = min($maxScenes, max(3, $minScenes));
        $referenceImages = is_array($input['referenceImages'] ?? null) ? $input['referenceImages'] : [];
        $hasReferenceImages = count($referenceImages) > 0;
        $language = strtolower(trim((string) ($input['language'] ?? 'english')));
        $isTurkishLanguage = str_starts_with($language, 'turk');
        $targetSceneCount = $this->deriveTargetSceneCount(
            $durationSeconds,
            $minScenes,
            $maxScenes,
            count($referenceImages)
        );
        $targetSpeechSeconds = max(5, $durationSeconds - 2);
        $wordsPerSecond = $isTurkishLanguage ? 2.05 : 2.35;
        $voiceWordTarget = (int) round($targetSpeechSeconds * $wordsPerSecond);
        $voiceWordMin = max(14, $voiceWordTarget - 4);
        $voiceWordMax = max($voiceWordMin + 4, min(58, $voiceWordTarget + 6));
        $rules = [
            sprintf('Exactly %d scenes', $targetSceneCount),
            sprintf('Use %d fps unless there is a strong reason not to', $defaultFps),
            'Each scene should include a concrete visual image prompt',
            'Mention product/brand naturally',
            'Include CTA in final scene text',
            'If includePrice=true then mention priceText in at least one overlay text',
            'Each overlayText max 7 words and max 42 characters',
            'Avoid hashtags and emojis in overlayText',
            'Avoid internet slang words like lol, lmao, xd',
            sprintf('Voiceover script should be between %d and %d words', $voiceWordMin, $voiceWordMax),
            'Voiceover must flow across the whole ad and end about 2 seconds before video end',
            sprintf('For 14+ second videos keep pacing dynamic with %d to %d scenes', $dynamicMinScenes, $maxScenes),
            'Image prompts must look photorealistic, natural and physically plausible',
            'Avoid collage or cutout style visuals',
            'Product must be the visual hero of the scene — prominent but physically believable, scaled to its real-world context',
        ];
        if ($hasReferenceImages) {
            $rules[] = 'Use reference images only for product identity; create new compositions with different angles/backgrounds';
            $rules[] = 'Never replicate the exact original framing of a reference image';
            $rules[] = 'When possible, reuse a reference image in multiple distinct scenes with different contexts';
        }

        $messages = [
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'You generate short-form video ad specs for Remotion.',
                    'Return strict JSON only.',
                    'No markdown, no extra commentary.',
                    'Respect requested duration and language.',
                    'Write concise, high-conversion overlay text.',
                    'Overlay text must be short punchy phrases, not long sentences.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'task' => 'Generate a RemotionRenderSpec',
                    'schema' => [
                        'durationSeconds' => 'number',
                        'fps' => 'number',
                        'format' => 'mp4',
                        'aspectRatio' => '9:16',
                        'scenes' => [[
                            'durationSeconds' => 'number',
                            'imagePrompt' => 'string',
                            'overlayText' => 'string',
                            'textAnimations' => ['string'],
                            'transition' => 'string',
                            'referenceImageIndex' => 'number|null',
                        ]],
                        'voiceover' => [
                            'enabled' => 'boolean',
                            'script' => 'string',
                            'gender' => 'male|female',
                            'style' => 'serious|friendly|energetic',
                        ],
                    ],
                    'input' => $input,
                    'rules' => $rules,
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        $requestTimeout = max(300, (int) config('services.openai.request_timeout_seconds', 900));

        $response = Http::timeout($requestTimeout)
            ->withToken($apiKey)
            ->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'temperature' => 0.2,
                'response_format' => [
                    'type' => 'json_object',
                ],
                'messages' => $messages,
            ]);

        if (!$response->ok()) {
            throw new RuntimeException('OpenAI spec generation failed');
        }

        $content = $response->json('choices.0.message.content');
        if (is_array($content)) {
            $content = collect($content)
                ->map(fn ($part) => is_string($part) ? $part : ((string) ($part['text'] ?? '')))
                ->implode("\n");
        }

        $text = trim((string) $content);
        $text = preg_replace('/```(?:json)?\s*([\s\S]*?)```/i', '$1', $text) ?: $text;

        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            $first = strpos($text, '{');
            $last = strrpos($text, '}');
            if ($first !== false && $last !== false && $first < $last) {
                $parsed = json_decode(substr($text, $first, $last - $first + 1), true);
            }
        }

        if (!is_array($parsed)) {
            throw new RuntimeException('OpenAI response is not valid JSON');
        }

        return $this->normalizeSpec($parsed, $input, $durationSeconds, $targetSceneCount);
    }

    private function normalizeSpec(
        array $raw,
        array $input,
        int $durationSeconds,
        int $targetSceneCount
    ): array {
        $minScenes = max(2, (int) config('video.min_scenes', 3));
        $maxScenes = max($minScenes, (int) config('video.max_scenes', 4));
        $targetSceneCount = max($minScenes, min($maxScenes, $targetSceneCount));
        $referenceImages = is_array($input['referenceImages'] ?? null) ? $input['referenceImages'] : [];
        $referenceCount = count($referenceImages);
        $defaultFps = $this->resolveDefaultFps();

        $fps = (int) ($raw['fps'] ?? $defaultFps);
        if ($fps < 24 || $fps > 60) {
            $fps = $defaultFps;
        }
        $fps = min($fps, $defaultFps);

        $aspectRatio = trim((string) ($raw['aspectRatio'] ?? ($input['aspectRatio'] ?? '9:16')));
        if ($aspectRatio === '') {
            $aspectRatio = '9:16';
        }

        $rawScenes = is_array($raw['scenes'] ?? null) ? $raw['scenes'] : [];
        $scenes = [];

        foreach ($rawScenes as $scene) {
            if (!is_array($scene)) {
                continue;
            }

            $sceneDuration = max(1, (int) ($scene['durationSeconds'] ?? 0));
            $imagePrompt = trim((string) ($scene['imagePrompt'] ?? ''));
            $overlayText = $this->sanitizeOverlayText((string) ($scene['overlayText'] ?? ''));
            $transition = trim((string) ($scene['transition'] ?? 'cut'));
            $referenceImageIndex = (int) ($scene['referenceImageIndex'] ?? 0);

            if ($overlayText === '') {
                continue;
            }

            if ($imagePrompt === '') {
                $imagePrompt = $this->fallbackImagePrompt(
                    $input,
                    $overlayText,
                    $referenceImageIndex > 0 ? $referenceImageIndex : null
                );
            }

            $textAnimations = is_array($scene['textAnimations'] ?? null)
                ? array_values(array_filter(array_map(
                    static fn ($anim) => trim((string) $anim),
                    $scene['textAnimations']
                )))
                : [];

            $normalizedScene = [
                'durationSeconds' => $sceneDuration,
                'imagePrompt' => $imagePrompt,
                'overlayText' => $overlayText,
                'textAnimations' => $textAnimations,
                'transition' => $transition !== '' ? $transition : 'cut',
            ];

            if ($referenceCount > 0 && $referenceImageIndex > 0 && $referenceImageIndex <= $referenceCount) {
                $normalizedScene['referenceImageIndex'] = $referenceImageIndex;
            }

            $scenes[] = $normalizedScene;
        }

        if ($scenes === []) {
            $scenes = $this->fallbackScenes($input, $targetSceneCount);
        }

        while (count($scenes) < $targetSceneCount) {
            foreach ($this->fallbackScenes($input, $targetSceneCount) as $fallbackScene) {
                if (count($scenes) >= $targetSceneCount) {
                    break;
                }
                $scenes[] = $fallbackScene;
            }
        }

        if (count($scenes) > $targetSceneCount) {
            $scenes = array_slice($scenes, 0, $targetSceneCount);
        }

        $this->assignReferenceImageIndexes($scenes, $referenceCount);
        $this->normalizeSceneDurations($scenes, $durationSeconds);

        $voiceInput = is_array($input['voice'] ?? null) ? $input['voice'] : [];
        $voiceoverRaw = is_array($raw['voiceover'] ?? null) ? $raw['voiceover'] : [];

        $voiceEnabled = (bool) ($voiceInput['enabled'] ?? false);
        $voiceGender = trim((string) ($voiceoverRaw['gender'] ?? ($voiceInput['gender'] ?? 'female')));
        $voiceStyle = trim((string) ($voiceoverRaw['style'] ?? ($voiceInput['style'] ?? 'friendly')));

        if (!in_array($voiceGender, ['male', 'female'], true)) {
            $voiceGender = 'female';
        }

        if (!in_array($voiceStyle, ['serious', 'friendly', 'energetic'], true)) {
            $voiceStyle = 'friendly';
        }

        $voiceScript = trim((string) ($voiceoverRaw['script'] ?? ''));
        if ($voiceEnabled && $voiceScript === '') {
            $voiceScript = implode('. ', array_map(
                static fn (array $scene) => trim((string) ($scene['overlayText'] ?? '')),
                $scenes
            ));
        }
        $voiceScript = $this->normalizeVoiceScript($voiceScript);
        if ($voiceEnabled) {
            $voiceScript = $this->ensureVoiceScriptCoverage($voiceScript, $input, $scenes, $durationSeconds);
        }

        return [
            'durationSeconds' => $durationSeconds,
            'fps' => $fps,
            'format' => 'mp4',
            'aspectRatio' => $aspectRatio,
            'tone' => (string) ($input['tone'] ?? 'Bold'),
            'platform' => (string) ($input['platform'] ?? 'TikTok'),
            'language' => (string) ($input['language'] ?? 'English'),
            'scenes' => $scenes,
            'voiceover' => [
                'enabled' => $voiceEnabled,
                'script' => $voiceScript,
                'gender' => $voiceGender,
                'style' => $voiceStyle,
            ],
        ];
    }

    private function resolveDefaultFps(): int
    {
        $fps = (int) config('video.default_fps', 24);
        return max(24, min(60, $fps));
    }

    private function deriveTargetSceneCount(
        int $durationSeconds,
        int $minScenes,
        int $maxScenes,
        int $referenceImageCount
    ): int {
        $durationBased = (int) round($durationSeconds / 3.7);
        if ($durationSeconds >= 14) {
            $durationBased = max($durationBased, min($maxScenes, 4));
        }
        if ($durationSeconds >= 18) {
            $durationBased = max($durationBased, min($maxScenes, 4));
        }
        $durationBased = max($minScenes, min($maxScenes, $durationBased));

        if ($referenceImageCount <= 0) {
            return $durationBased;
        }

        $referenceBased = max($minScenes, min($maxScenes, $referenceImageCount + 1));
        if ($durationSeconds >= 14) {
            $referenceBased = max($referenceBased, min($maxScenes, 4));
        }

        return max($durationBased, $referenceBased);
    }

    private function normalizeSceneDurations(array &$scenes, int $targetDurationSeconds): void
    {
        if ($scenes === []) {
            return;
        }

        $weights = array_map(static function (array $scene): float {
            return max(1.0, (float) ($scene['durationSeconds'] ?? 1));
        }, $scenes);

        $weightSum = array_sum($weights);
        if ($weightSum <= 0) {
            $weightSum = count($scenes);
        }

        $raw = [];
        $durations = [];
        $used = 0;
        foreach ($weights as $index => $weight) {
            $rawValue = ($weight / $weightSum) * $targetDurationSeconds;
            $raw[$index] = $rawValue;
            $duration = max(1, (int) floor($rawValue));
            $durations[$index] = $duration;
            $used += $duration;
        }

        $remaining = $targetDurationSeconds - $used;

        if ($remaining > 0) {
            $fractions = [];
            foreach ($raw as $index => $value) {
                $fractions[$index] = $value - floor($value);
            }
            arsort($fractions);
            $ordered = array_keys($fractions);
            $cursor = 0;
            while ($remaining > 0 && $ordered !== []) {
                $targetIndex = $ordered[$cursor % count($ordered)];
                $durations[$targetIndex] += 1;
                $remaining -= 1;
                $cursor += 1;
            }
        } elseif ($remaining < 0) {
            $fractions = [];
            foreach ($raw as $index => $value) {
                $fractions[$index] = $value - floor($value);
            }
            asort($fractions);
            $ordered = array_keys($fractions);
            $cursor = 0;
            while ($remaining < 0 && $ordered !== []) {
                $targetIndex = $ordered[$cursor % count($ordered)];
                if ($durations[$targetIndex] > 1) {
                    $durations[$targetIndex] -= 1;
                    $remaining += 1;
                }
                $cursor += 1;
                if ($cursor > count($ordered) * 6) {
                    break;
                }
            }
        }

        foreach ($scenes as $index => &$scene) {
            $scene['durationSeconds'] = max(1, (int) ($durations[$index] ?? 1));
        }
        unset($scene);
    }

    private function fallbackScenes(array $input, int $count): array
    {
        $productName = trim((string) ($input['productName'] ?? 'Product'));
        $productDescription = trim((string) ($input['productDescription'] ?? ''));
        $priceText = trim((string) ($input['priceText'] ?? ''));
        $cta = trim((string) ($input['cta'] ?? 'Shop now'));
        $referenceImages = is_array($input['referenceImages'] ?? null) ? $input['referenceImages'] : [];
        $referenceCount = count($referenceImages);

        $base = [
            [
                'overlayText' => "Meet {$productName}",
                'imagePrompt' => $this->fallbackImagePrompt($input, "Hero visual of {$productName}", 1),
                'textAnimations' => ['fade-in'],
                'transition' => 'fade',
            ],
            [
                'overlayText' => $productDescription !== '' ? $productDescription : 'Daily comfort and style',
                'imagePrompt' => $this->fallbackImagePrompt($input, "Lifestyle shot with {$productName}", 2),
                'textAnimations' => ['slide-up'],
                'transition' => 'cut',
            ],
            [
                'overlayText' => $priceText !== '' ? $priceText : 'Limited time offer',
                'imagePrompt' => $this->fallbackImagePrompt($input, "Price highlight frame for {$productName}", 3),
                'textAnimations' => ['pop'],
                'transition' => 'cut',
            ],
            [
                'overlayText' => 'Trusted by everyday users',
                'imagePrompt' => $this->fallbackImagePrompt($input, "Social proof visual for {$productName}", 4),
                'textAnimations' => ['fade-in'],
                'transition' => 'fade',
            ],
            [
                'overlayText' => $cta,
                'imagePrompt' => $this->fallbackImagePrompt($input, "Strong CTA frame for {$productName}", 5),
                'textAnimations' => ['pulse'],
                'transition' => 'fade',
            ],
        ];

        $fallback = [];
        $target = max(1, $count);
        for ($i = 0; $i < $target; $i++) {
            $seed = $base[$i % count($base)];
            $scene = [
                'durationSeconds' => 3,
                'imagePrompt' => (string) $seed['imagePrompt'],
                'overlayText' => $this->sanitizeOverlayText((string) $seed['overlayText']),
                'textAnimations' => is_array($seed['textAnimations']) ? $seed['textAnimations'] : ['fade-in'],
                'transition' => (string) $seed['transition'],
            ];

            if ($referenceCount > 0) {
                $scene['referenceImageIndex'] = ($i % $referenceCount) + 1;
            }

            $fallback[] = $scene;
        }

        return $fallback;
    }

    private function fallbackImagePrompt(array $input, string $overlayText, ?int $referenceIndex = null): string
    {
        $productName = trim((string) ($input['productName'] ?? 'Product'));
        $brandName = trim((string) ($input['brandName'] ?? ''));
        $tone = trim((string) ($input['tone'] ?? 'Bold'));
        $platform = trim((string) ($input['platform'] ?? 'TikTok'));
        $referenceImages = is_array($input['referenceImages'] ?? null) ? $input['referenceImages'] : [];

        $parts = [
            "Commercial ad visual for {$productName}",
            $brandName !== '' ? "brand {$brandName}" : null,
            "tone {$tone}",
            "platform {$platform}",
            $overlayText,
            'cinematic lighting, high detail, vertical framing, no watermark',
        ];

        if ($referenceImages !== []) {
            $parts[] = 'use reference only for product identity and key details';
            $parts[] = 'create a new composition with different camera angle, setting and lighting';
            $parts[] = 'natural product integration, realistic shadows and reflections, no pasted or cutout look';
            $parts[] = 'never replicate the original reference framing or background';
            $parts[] = 'maintain physically accurate product scale relative to people and environment';
            if ($referenceIndex !== null && $referenceIndex > 0) {
                $parts[] = 'use reference image #'.$referenceIndex;
            }
        } else {
            $parts[] = 'photorealistic lifestyle ad scene, natural human interaction, realistic shadows';
        }

        return implode(', ', array_values(array_filter($parts)));
    }

    private function ensureVoiceScriptCoverage(
        string $script,
        array $input,
        array $scenes,
        int $durationSeconds
    ): string {
        $normalized = $this->normalizeVoiceScript($script);
        if ($normalized === '') {
            return '';
        }

        $language = strtolower(trim((string) ($input['language'] ?? 'english')));
        $isTurkish = str_starts_with($language, 'turk');
        $targetSpeechSeconds = max(5, $durationSeconds - 2);
        $wordsPerSecond = $isTurkish ? 2.05 : 2.35;
        $maxWords = max(16, min(64, (int) ceil($targetSpeechSeconds * $wordsPerSecond) + 5));
        $wordCount = $this->countWords($normalized);

        if ($wordCount > $maxWords) {
            $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $tokens = array_slice($tokens, 0, $maxWords);
            $normalized = implode(' ', $tokens);
            if (!preg_match('/[.!?]$/u', $normalized)) {
                $normalized .= '.';
            }
        }

        return $this->normalizeVoiceScript($normalized);
    }

    private function countWords(string $value): int
    {
        preg_match_all('/[\p{L}\p{N}\'’\-]+/u', $value, $matches);

        return count($matches[0] ?? []);
    }

    private function sanitizeOverlayText(string $text): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
        if ($normalized === '') {
            return '';
        }

        $normalized = str_replace(['#', '*', '_'], '', $normalized);
        $normalized = preg_replace('/\b(?:lol|lmao|lmfao|xd|wtf|bro)\b/iu', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? $normalized;

        $words = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) > 7) {
            $words = array_slice($words, 0, 7);
            $normalized = implode(' ', $words);
        }

        if (mb_strlen($normalized) > 42) {
            $normalized = mb_substr($normalized, 0, 42);
            $normalized = rtrim($normalized, " \t\n\r\0\x0B.,;:!?");
        }

        return trim($normalized);
    }

    private function normalizeVoiceScript(string $script): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($script)) ?? '';
        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/\b(?:lol|lmao|lmfao|xd|wtf|bro)\b/iu', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? $normalized;
        if ($normalized === '') {
            return '';
        }

        if (!preg_match('/[.!?]$/u', $normalized)) {
            $normalized .= '.';
        }

        return $normalized;
    }

    private function assignReferenceImageIndexes(array &$scenes, int $referenceCount): void
    {
        if ($referenceCount <= 0) {
            return;
        }

        foreach ($scenes as $index => &$scene) {
            $existing = (int) ($scene['referenceImageIndex'] ?? 0);
            if ($existing >= 1 && $existing <= $referenceCount) {
                continue;
            }

            $scene['referenceImageIndex'] = ($index % $referenceCount) + 1;
        }
        unset($scene);
    }
}
