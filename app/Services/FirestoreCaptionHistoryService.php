<?php

namespace App\Services;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FirestoreCaptionHistoryService
{
    private const CAPTION_HISTORY_COLLECTION = 'caption_history';
    private const FIRESTORE_SCOPE = 'https://www.googleapis.com/auth/datastore';

    private array $credentials;
    private string $projectId;
    private string $databaseId;

    public function __construct()
    {
        $b64 = config('firebase.credentials_b64');
        if (!is_string($b64) || trim($b64) === '') {
            throw new RuntimeException('FIREBASE_CREDENTIALS_B64 missing');
        }

        $decoded = base64_decode($b64, true);
        if ($decoded === false) {
            throw new RuntimeException('FIREBASE_CREDENTIALS_B64 invalid base64');
        }

        $credentials = json_decode($decoded, true);
        if (!is_array($credentials)) {
            throw new RuntimeException('Firebase credentials JSON invalid');
        }

        $projectId = trim((string) (config('firebase.project_id') ?: ($credentials['project_id'] ?? '')));
        if ($projectId === '') {
            throw new RuntimeException('Firebase project_id missing in credentials');
        }

        $this->credentials = $credentials;
        $this->projectId = $projectId;
        $this->databaseId = trim((string) config('firebase.firestore_database', '(default)')) ?: '(default)';
    }

    public function saveGeneration(string $uid, array $input, array $captions): void
    {
        $captionValues = [];
        foreach ($captions as $item) {
            if (!is_array($item)) {
                continue;
            }

            $caption = trim((string) ($item['caption'] ?? ''));
            if ($caption === '') {
                continue;
            }

            $captionValues[] = [
                'mapValue' => [
                    'fields' => [
                        'caption' => ['stringValue' => $caption],
                        'hashtags' => ['stringValue' => trim((string) ($item['hashtags'] ?? ''))],
                    ],
                ],
            ];
        }

        if ($captionValues === []) {
            return;
        }

        $payload = [
            'fields' => [
                'productName' => ['stringValue' => trim((string) ($input['productName'] ?? ''))],
                'productDescription' => ['stringValue' => trim((string) ($input['productDescription'] ?? ''))],
                'platforms' => $this->encodeStringArrayField($input['platforms'] ?? []),
                'tone' => ['stringValue' => trim((string) ($input['tone'] ?? ''))],
                'captionStyle' => ['stringValue' => trim((string) ($input['captionStyle'] ?? ''))],
                'language' => ['stringValue' => trim((string) ($input['language'] ?? ''))],
                'captions' => ['arrayValue' => ['values' => $captionValues]],
                'previewText' => ['stringValue' => (string) ($captionValues[0]['mapValue']['fields']['caption']['stringValue'] ?? '')],
                'createdAt' => ['timestampValue' => gmdate('c')],
            ],
        ];

        $response = Http::timeout(15)
            ->withToken($this->getAccessToken())
            ->acceptJson()
            ->post($this->collectionUrl($uid), $payload);

        if (!$response->successful()) {
            throw new RuntimeException('Firestore write failed: '.$response->status());
        }
    }

    public function getRecent(string $uid, int $limit = 10): array
    {
        $response = Http::timeout(15)
            ->withToken($this->getAccessToken())
            ->acceptJson()
            ->get($this->collectionUrl($uid), [
                'pageSize' => $limit,
                'orderBy' => 'createdAt desc',
            ]);

        if ($response->status() === 404) {
            return [];
        }

        if (!$response->successful()) {
            throw new RuntimeException('Firestore read failed: '.$response->status());
        }

        $documents = $response->json('documents');
        if (!is_array($documents)) {
            return [];
        }

        $items = [];
        foreach ($documents as $doc) {
            if (!is_array($doc)) {
                continue;
            }

            $fields = is_array($doc['fields'] ?? null) ? $this->decodeFields($doc['fields']) : [];
            $captionOptions = [];

            $rawCaptions = is_array($fields['captions'] ?? null) ? $fields['captions'] : [];
            foreach ($rawCaptions as $rawItem) {
                if (!is_array($rawItem)) {
                    continue;
                }

                $caption = trim((string) ($rawItem['caption'] ?? ''));
                if ($caption === '') {
                    continue;
                }

                $captionOptions[] = [
                    'caption' => $caption,
                    'hashtags' => trim((string) ($rawItem['hashtags'] ?? '')),
                ];
            }

            $previewText = trim((string) ($fields['previewText'] ?? ''));
            if ($previewText === '' && $captionOptions !== []) {
                $previewText = $captionOptions[0]['caption'];
            }

            if ($previewText === '') {
                continue;
            }

            $createdAt = trim((string) ($fields['createdAt'] ?? ($doc['createTime'] ?? '')));
            $name = (string) ($doc['name'] ?? '');
            $id = '';
            if ($name !== '') {
                $parts = explode('/', $name);
                $id = (string) end($parts);
            }

            $items[] = [
                'id' => $id,
                'text' => $previewText,
                'hashtags' => $captionOptions[0]['hashtags'] ?? '',
                'captions' => $captionOptions,
                'productName' => trim((string) ($fields['productName'] ?? '')),
                'platforms' => array_values(array_filter(
                    is_array($fields['platforms'] ?? null) ? $fields['platforms'] : [],
                    fn ($platform) => is_string($platform) && trim($platform) !== ''
                )),
                'tone' => trim((string) ($fields['tone'] ?? '')),
                'captionStyle' => trim((string) ($fields['captionStyle'] ?? '')),
                'language' => trim((string) ($fields['language'] ?? '')),
                'createdAt' => $createdAt,
            ];
        }

        return $items;
    }

    private function getAccessToken(): string
    {
        $token = (new ServiceAccountCredentials([self::FIRESTORE_SCOPE], $this->credentials))->fetchAuthToken();
        $accessToken = is_array($token) ? (string) ($token['access_token'] ?? '') : '';

        if ($accessToken === '') {
            throw new RuntimeException('Unable to get Google access token');
        }

        return $accessToken;
    }

    private function collectionUrl(string $uid): string
    {
        $safeUid = rawurlencode(trim($uid));
        return sprintf(
            'https://firestore.googleapis.com/v1/projects/%s/databases/%s/documents/users/%s/%s',
            rawurlencode($this->projectId),
            rawurlencode($this->databaseId),
            $safeUid,
            self::CAPTION_HISTORY_COLLECTION
        );
    }

    private function encodeStringArrayField(array $items): array
    {
        $values = [];

        foreach ($items as $item) {
            $text = trim((string) $item);
            if ($text === '') {
                continue;
            }
            $values[] = ['stringValue' => $text];
        }

        return ['arrayValue' => ['values' => $values]];
    }

    private function decodeFields(array $fields): array
    {
        $decoded = [];
        foreach ($fields as $key => $value) {
            if (!is_array($value) || !is_string($key)) {
                continue;
            }
            $decoded[$key] = $this->decodeValue($value);
        }
        return $decoded;
    }

    private function decodeValue(array $value): mixed
    {
        if (array_key_exists('stringValue', $value)) {
            return (string) $value['stringValue'];
        }

        if (array_key_exists('timestampValue', $value)) {
            return (string) $value['timestampValue'];
        }

        if (array_key_exists('booleanValue', $value)) {
            return (bool) $value['booleanValue'];
        }

        if (array_key_exists('integerValue', $value)) {
            return (int) $value['integerValue'];
        }

        if (array_key_exists('doubleValue', $value)) {
            return (float) $value['doubleValue'];
        }

        if (array_key_exists('nullValue', $value)) {
            return null;
        }

        if (array_key_exists('arrayValue', $value)) {
            $values = $value['arrayValue']['values'] ?? [];
            if (!is_array($values)) {
                return [];
            }
            return array_map(
                fn ($item) => is_array($item) ? $this->decodeValue($item) : null,
                $values
            );
        }

        if (array_key_exists('mapValue', $value)) {
            $fields = $value['mapValue']['fields'] ?? [];
            return is_array($fields) ? $this->decodeFields($fields) : [];
        }

        return null;
    }
}
