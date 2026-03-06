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

        $messages = [
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'You generate short-form video ad specs for Remotion.',
                    'Return strict JSON only.',
                    'No markdown, no extra commentary.',
                    'Respect requested duration and language.',
                    'Write concise, high-conversion overlay text.',
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
                        ]],
                        'voiceover' => [
                            'enabled' => 'boolean',
                            'script' => 'string',
                            'gender' => 'male|female',
                            'style' => 'serious|friendly|energetic',
                        ],
                    ],
                    'input' => $input,
                    'rules' => [
                        sprintf('At least %d scenes, at most %d scenes', $minScenes, $maxScenes),
                        sprintf('Use %d fps unless there is a strong reason not to', $defaultFps),
                        'Each scene should include a concrete visual image prompt',
                        'Mention product/brand naturally',
                        'Include CTA in final scene text',
                        'If includePrice=true then mention priceText in at least one overlay text',
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        $response = Http::timeout(45)
            ->withToken($apiKey)
            ->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'temperature' => 0.4,
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

        return $this->normalizeSpec($parsed, $input, $durationSeconds);
    }

    private function normalizeSpec(array $raw, array $input, int $durationSeconds): array
    {
        $minScenes = max(2, (int) config('video.min_scenes', 3));
        $maxScenes = max($minScenes, (int) config('video.max_scenes', 4));
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
            $overlayText = trim((string) ($scene['overlayText'] ?? ''));
            $transition = trim((string) ($scene['transition'] ?? 'cut'));

            if ($overlayText === '') {
                continue;
            }

            if ($imagePrompt === '') {
                $imagePrompt = $this->fallbackImagePrompt($input, $overlayText);
            }

            $textAnimations = is_array($scene['textAnimations'] ?? null)
                ? array_values(array_filter(array_map(
                    static fn ($anim) => trim((string) $anim),
                    $scene['textAnimations']
                )))
                : [];

            $scenes[] = [
                'durationSeconds' => $sceneDuration,
                'imagePrompt' => $imagePrompt,
                'overlayText' => $overlayText,
                'textAnimations' => $textAnimations,
                'transition' => $transition !== '' ? $transition : 'cut',
            ];
        }

        if ($scenes === []) {
            $scenes = $this->fallbackScenes($input);
        }

        if (count($scenes) < $minScenes) {
            $scenes = array_merge($scenes, $this->fallbackScenes($input));
            $scenes = array_slice($scenes, 0, $minScenes);
        }

        if (count($scenes) > $maxScenes) {
            $scenes = array_slice($scenes, 0, $maxScenes);
        }

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
            $voiceScript = implode(' ', array_map(
                static fn (array $scene) => $scene['overlayText'],
                $scenes
            ));
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

    private function normalizeSceneDurations(array &$scenes, int $targetDurationSeconds): void
    {
        $total = array_sum(array_map(
            static fn (array $scene) => (int) $scene['durationSeconds'],
            $scenes
        ));

        if ($total <= 0) {
            $equal = max(1, (int) floor($targetDurationSeconds / max(1, count($scenes))));
            foreach ($scenes as &$scene) {
                $scene['durationSeconds'] = $equal;
            }
            unset($scene);
            $total = array_sum(array_column($scenes, 'durationSeconds'));
        }

        $scale = $targetDurationSeconds / max(1, $total);
        $normalizedTotal = 0;

        foreach ($scenes as $index => $scene) {
            $scaled = max(1, (int) round($scene['durationSeconds'] * $scale));
            $scenes[$index]['durationSeconds'] = $scaled;
            $normalizedTotal += $scaled;
        }

        $diff = $targetDurationSeconds - $normalizedTotal;
        if ($diff !== 0 && $scenes !== []) {
            $lastIndex = count($scenes) - 1;
            $scenes[$lastIndex]['durationSeconds'] = max(1, $scenes[$lastIndex]['durationSeconds'] + $diff);
        }
    }

    private function fallbackScenes(array $input): array
    {
        $productName = trim((string) ($input['productName'] ?? 'Product'));
        $priceText = trim((string) ($input['priceText'] ?? ''));
        $cta = trim((string) ($input['cta'] ?? 'Shop now'));

        return [
            [
                'durationSeconds' => 4,
                'imagePrompt' => $this->fallbackImagePrompt($input, "Hero shot of {$productName}"),
                'overlayText' => "Meet {$productName}",
                'textAnimations' => ['fade-in', 'slide-up'],
                'transition' => 'fade',
            ],
            [
                'durationSeconds' => 4,
                'imagePrompt' => $this->fallbackImagePrompt($input, "Lifestyle use case of {$productName}"),
                'overlayText' => (string) ($input['productDescription'] ?? 'Built to solve your daily pain point'),
                'textAnimations' => ['zoom-in'],
                'transition' => 'cut',
            ],
            [
                'durationSeconds' => 4,
                'imagePrompt' => $this->fallbackImagePrompt($input, "Conversion-focused final frame for {$productName}"),
                'overlayText' => $priceText !== '' ? "{$priceText} • {$cta}" : $cta,
                'textAnimations' => ['pulse'],
                'transition' => 'fade',
            ],
        ];
    }

    private function fallbackImagePrompt(array $input, string $overlayText): string
    {
        $productName = trim((string) ($input['productName'] ?? 'Product'));
        $brandName = trim((string) ($input['brandName'] ?? ''));
        $tone = trim((string) ($input['tone'] ?? 'Bold'));
        $platform = trim((string) ($input['platform'] ?? 'TikTok'));

        $parts = [
            "Commercial ad visual for {$productName}",
            $brandName !== '' ? "brand {$brandName}" : null,
            "tone {$tone}",
            "platform {$platform}",
            $overlayText,
            'cinematic lighting, high detail, no watermark, vertical framing',
        ];

        return implode(', ', array_values(array_filter($parts)));
    }
}
