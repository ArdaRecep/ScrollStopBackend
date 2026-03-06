<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OpenAIVoiceoverService
{
    public function maybeGenerate(array $renderSpec, string $workDir): ?string
    {
        $voiceover = is_array($renderSpec['voiceover'] ?? null) ? $renderSpec['voiceover'] : [];
        $enabled = (bool) ($voiceover['enabled'] ?? false);
        $script = trim((string) ($voiceover['script'] ?? ''));

        if (! $enabled || $script === '') {
            return null;
        }

        $apiKey = trim((string) config('services.openai.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY missing for voiceover');
        }

        // Öneri: varsayılanı tts-1-hd yapabilirsin (daha doğal)
        $model = trim((string) config('services.openai.tts_model', 'tts-1-hd'));

        $voice = $this->pickVoice(
            (string) ($voiceover['gender'] ?? 'female'),
            (string) ($voiceover['style'] ?? 'friendly')
        );

        if (!is_dir($workDir) && !mkdir($workDir, 0775, true) && !is_dir($workDir)) {
            throw new RuntimeException('Unable to create temporary audio directory');
        }

        $response = Http::timeout(60)
            ->withToken($apiKey)
            ->post('https://api.openai.com/v1/audio/speech', [
                'model' => $model,
                'voice' => $voice,
                'input' => $script,
                'response_format' => 'mp3',
            ]);

        if (! $response->ok()) {
            $msg = sprintf(
                'OpenAI voiceover generation failed (status %d): %s',
                $response->status(),
                mb_substr((string) $response->body(), 0, 400)
            );
            throw new RuntimeException($msg);
        }

        $targetPath = rtrim($workDir, '/').'/voiceover-'.Str::uuid().'.mp3';

        if (file_put_contents($targetPath, $response->body()) === false) {
            throw new RuntimeException('Unable to write generated voiceover file');
        }

        return $targetPath;
    }

    private function pickVoice(string $gender, string $style): string
    {
        $gender = strtolower(trim($gender));
        $style = strtolower(trim($style));

        if ($gender === 'male') {
            return match ($style) {
                'energetic' => 'fable',
                'serious' => 'onyx',
                default => 'echo',
            };
        }

        return match ($style) {
            'energetic' => 'shimmer',
            'serious' => 'alloy',
            default => 'nova',
        };
    }
}