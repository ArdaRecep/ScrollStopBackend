<?php

namespace App\Http\Controllers;

use App\Services\FirestoreCaptionHistoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CaptionController extends Controller
{
    public function generate(Request $request, FirestoreCaptionHistoryService $historyService)
    {
        $data = $request->validate([
            'productName' => 'required|string|max:200',
            'productDescription' => 'nullable|string|max:2000',
            'platforms' => 'required|array|min:1',
            'platforms.*' => 'string|max:50',
            'tone' => 'required|string|max:50',
            'captionStyle' => 'nullable|string|max:80',

            'language' => 'nullable|string|max:40',
            'hashtagCount' => 'nullable|integer|min:0|max:30',
            'maxCaptionChars' => 'nullable|integer|min:50|max:400',
            'includeEmojis' => 'nullable|boolean',
            'cta' => 'nullable|string|max:120',
            'audience' => 'nullable|string|max:120',
            'avoidClaims' => 'nullable|array|max:20',
            'avoidClaims.*' => 'string|max:40',
        ]);

        $apiKey = config('openrouter.api');
        if (!$apiKey) {
            return response()->json(['error' => 'OPENROUTER_API_KEY missing'], 500);
        }

        $model = config('openrouter.model');

        $systemPrompt = implode("\n", [
          "You are an expert social media direct-response ad copywriter.",
          "Task: generate short-form ad captions for social platforms.",
          "Important:",
          "- Do NOT invent product features or claims not provided by the user.",
          "- If product details are missing, keep claims generic (e.g., \"everyday protection\").",
          "- Keep language natural, conversion-oriented, and platform-appropriate.",
          "",
          "Output rules:",
          "- Return ONLY valid JSON (no markdown, no extra text).",
          "- Provide exactly 3 caption options.",
          "- Each option must be meaningfully different in angle:",
          "  1) Feature/benefit focused",
          "  2) Problem/solution or pain-point hook",
          "  3) Urgency/offer/CTA focused (even if generic)",
          "",
          "JSON schema:",
          "{ \"captions\": [ { \"caption\": \"string\", \"hashtags\": \"#tag1 #tag2 ...\" } ] }",
        ]);

        $avoidClaimsText = '';
        if (!empty($data['avoidClaims']) && is_array($data['avoidClaims'])) {
            $avoidClaimsText = implode(', ', array_filter(array_map('trim', $data['avoidClaims'])));
        }

        $userPromptLines = array_filter([
          "Language: " . ($data['language'] ?? 'English'),
          "Target platforms: " . implode(', ', $data['platforms']),
          "Tone: " . $data['tone'],
          "Caption style: " . ($data['captionStyle'] ?? 'General ad caption'),
          "Max caption length: " . (isset($data['maxCaptionChars']) ? $data['maxCaptionChars'] : 140) . " characters",
          "Hashtag count: " . (isset($data['hashtagCount']) ? $data['hashtagCount'] : 8),
          "Emojis: " . (!empty($data['includeEmojis']) ? 'Allowed (keep minimal)' : 'None'),
          "CTA: " . (!empty($data['cta']) ? $data['cta'] : 'Not provided (use a generic CTA like \"Shop now\")'),
          "Audience: " . (!empty($data['audience']) ? $data['audience'] : 'Not provided'),
          $avoidClaimsText ? "Avoid claims/words: {$avoidClaimsText}" : null,
          "",
          "Product name: " . $data['productName'],
          "Product description: " . (!empty($data['productDescription']) ? $data['productDescription'] : 'Not provided.'),
          "",
          "Goal: drive clicks/conversions and improve in-platform discoverability.",
          "Include one explicit search keyword phrase in each caption in the requested language.",
        ]);

        $userPrompt = implode("\n", $userPromptLines);

        $resp = Http::timeout(20)->withHeaders([
          'Authorization' => "Bearer {$apiKey}",
          'HTTP-Referer' => env('APP_URL'),
          'X-Title' => 'ScrollStop Caption Generator',
        ])->post('https://openrouter.ai/api/v1/chat/completions', [
          'model' => $model,
          'temperature' => 0.55,
          'max_tokens' => 450,
          'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
          ],
        ]);

        if (!$resp->ok()) {
            $msg = $resp->json('error.message') ?? $resp->json('message') ?? 'OpenRouter request failed';
            return response()->json(['error' => $msg], 400);
        }

        $content = $resp->json('choices.0.message.content');

        // content bazen string, bazen array olabilir; burada string'e çevir
        if (is_array($content)) {
            $content = collect($content)->map(function ($p) {
                return is_string($p) ? $p : ($p['text'] ?? '');
            })->implode("\n");
        }

        $text = trim((string)$content);
        if ($text === '') {
            return response()->json(['error' => 'AI response empty'], 400);
        }

        // JSON parse (code fence vs vs)
        $text = preg_replace('/```(?:json)?\s*([\s\S]*?)```/i', '$1', $text);
        $text = trim($text);

        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            // brace slice fallback
            $first = strpos($text, '{');
            $last = strrpos($text, '}');
            if ($first === false || $last === false || $first >= $last) {
                return response()->json(['error' => 'AI response not valid JSON'], 400);
            }
            $parsed = json_decode(substr($text, $first, $last - $first + 1), true);
        }

        $rawCaptions = is_array($parsed['captions'] ?? null) ? $parsed['captions'] : [];
        $captions = [];

        foreach ($rawCaptions as $item) {
            if (!is_array($item)) continue;
            $cap = trim((string)($item['caption'] ?? ''));
            $tags = trim((string)($item['hashtags'] ?? ''));
            if ($cap === '') continue;
            $captions[] = ['caption' => $cap, 'hashtags' => $tags];
            if (count($captions) >= 3) break;
        }

        if (count($captions) < 1) {
            return response()->json(['error' => 'No captions returned'], 400);
        }

        $uid = trim((string) $request->attributes->get('firebase_uid', ''));
        if ($uid !== '') {
            try {
                $historyService->saveGeneration($uid, $data, $captions);
            } catch (\Throwable $e) {
                Log::warning('Caption history write failed', [
                    'uid' => $uid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['captions' => $captions]);
    }

    public function recent(Request $request, FirestoreCaptionHistoryService $historyService)
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:30',
        ]);

        $uid = trim((string) $request->attributes->get('firebase_uid', ''));
        if ($uid === '') {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        try {
            $items = $historyService->getRecent($uid, (int) ($validated['limit'] ?? 10));
        } catch (\Throwable $e) {
            Log::warning('Caption history read failed', [
                'uid' => $uid,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Caption history unavailable'], 500);
        }

        return response()->json(['items' => $items]);
    }
}
