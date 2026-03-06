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
        $script = $this->normalizeScript((string) ($voiceover['script'] ?? ''));

        if (! $enabled || $script === '') {
            return null;
        }

        $apiKey = trim((string) config('services.openai.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY missing for voiceover');
        }

        // Öneri: varsayılanı tts-1-hd yapabilirsin (daha doğal)
        $model = trim((string) config('services.openai.tts_model', 'tts-1-hd'));
        $speed = (float) config('services.openai.tts_speed', 0.95);
        $speed = max(0.85, min(1.1, $speed));

        $voice = $this->pickVoice(
            (string) ($voiceover['gender'] ?? 'female'),
            (string) ($voiceover['style'] ?? 'friendly'),
            (string) ($renderSpec['language'] ?? 'English')
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
                'speed' => $speed,
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

    private function pickVoice(string $gender, string $style, string $language): string
{
    $gender   = strtolower(trim($gender));
    $style    = strtolower(trim($style));
    $language = strtolower(trim($language));
    $isTurkish = str_starts_with($language, 'turk');

    if ($gender === 'male') {
        return match (true) {
            $style === 'energetic'              => 'ash',   // ash erkekte en dinamik
            $style === 'serious'                => 'onyx',  // onyx en ağır/ciddi
            $isTurkish                          => 'ash',   // Türkçe'de ash çok daha doğal
            default                             => 'fable', // fable İngilizce'de sıcak
        };
    }

    // Kadın
    return match (true) {
        $style === 'energetic' && $isTurkish  => 'coral',  // coral Türkçe'de en akıcı
        $style === 'energetic'                 => 'coral',
        $style === 'serious'   && $isTurkish  => 'nova',
        $style === 'serious'                   => 'sage',
        $isTurkish                             => 'nova',   // nova Türkçe'de en doğal kadın sesi
        default                                => 'nova',
    };
}

    private function normalizeScript(string $script): string
{
    $normalized = preg_replace('/\s+/u', ' ', trim($script)) ?: '';
    if ($normalized === '') {
        return '';
    }

    // Art arda noktalama işaretlerini temizle
    $normalized = preg_replace('/([.!?])\s*[.!?]+/u', '$1', $normalized);

    // Kısa cümle birleştirici — ses daha akıcı olur
    $normalized = preg_replace('/\.\s+([a-zçğışöüа-я])/u', '. $1', $normalized);

    if (!preg_match('/[.!?]$/u', $normalized)) {
        $normalized .= '.';
    }

    return $normalized;
}
}
