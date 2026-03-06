<?php

namespace App\Services;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FirestoreService
{
    private const FIRESTORE_SCOPE = 'https://www.googleapis.com/auth/datastore';

    public function __construct(private readonly FirebaseCredentialsService $firebaseCredentials)
    {
    }

    public function createDocument(string $collectionPath, string $documentId, array $fields): void
    {
        $query = ['documentId' => $documentId];

        $response = $this->request()
            ->withOptions(['query' => $query])
            ->post($this->collectionUrl($collectionPath), [
                'fields' => $this->encodeFields($fields),
            ]);

        if ($response->status() === 409) {
            throw new RuntimeException('Firestore document already exists');
        }

        if (!$response->successful()) {
            throw new RuntimeException('Firestore create failed: '.$response->status());
        }
    }

    public function updateDocument(string $documentPath, array $fields, ?array $fieldMask = null): void
    {
        $mask = $fieldMask ?? array_keys($fields);
        $url = $this->documentUrl($documentPath);

        if ($mask !== []) {
            $encoded = [];
            foreach (array_values($mask) as $fieldPath) {
                $field = trim((string) $fieldPath);
                if ($field === '') {
                    continue;
                }
                $encoded[] = 'updateMask.fieldPaths='.rawurlencode($field);
            }

            if ($encoded !== []) {
                $url .= '?'.implode('&', $encoded);
            }
        }

        $response = $this->request()->patch($url, [
            'fields' => $this->encodeFields($fields),
        ]);

        if (!$response->successful()) {
            throw new RuntimeException('Firestore update failed: '.$response->status());
        }
    }

    public function getDocument(string $documentPath): ?array
    {
        $response = $this->request()->get($this->documentUrl($documentPath));

        if ($response->status() === 404) {
            return null;
        }

        if (!$response->successful()) {
            throw new RuntimeException('Firestore read failed: '.$response->status());
        }

        $doc = $response->json();
        if (!is_array($doc)) {
            return null;
        }

        return $this->normalizeDocument($doc, $documentPath);
    }

    public function runStructuredQuery(array $structuredQuery): array
    {
        $response = $this->request()->post($this->runQueryUrl(), [
            'structuredQuery' => $structuredQuery,
        ]);

        if (!$response->successful()) {
            $body = trim((string) $response->body());
            $snippet = $body !== '' ? mb_substr($body, 0, 320) : 'no response body';
            throw new RuntimeException('Firestore query failed: '.$response->status().' '.$snippet);
        }

        $rows = $response->json();
        if (!is_array($rows)) {
            return [];
        }

        $documents = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $doc = $row['document'] ?? null;
            if (!is_array($doc)) {
                continue;
            }

            $normalized = $this->normalizeDocument($doc);
            if ($normalized !== null) {
                $documents[] = $normalized;
            }
        }

        return $documents;
    }

    private function request()
    {
        return Http::timeout(25)
            ->withToken($this->accessToken())
            ->acceptJson();
    }

    private function accessToken(): string
    {
        $credentials = new ServiceAccountCredentials(
            [self::FIRESTORE_SCOPE],
            $this->firebaseCredentials->credentials()
        );

        $token = $credentials->fetchAuthToken();
        $accessToken = is_array($token) ? (string) ($token['access_token'] ?? '') : '';

        if ($accessToken === '') {
            throw new RuntimeException('Unable to get Google access token for Firestore');
        }

        return $accessToken;
    }

    private function collectionUrl(string $collectionPath): string
    {
        $path = trim($collectionPath, '/');

        return sprintf(
            'https://firestore.googleapis.com/v1/projects/%s/databases/%s/documents/%s',
            rawurlencode($this->firebaseCredentials->projectId()),
            rawurlencode($this->firebaseCredentials->firestoreDatabase()),
            $this->encodePath($path),
        );
    }

    private function documentUrl(string $documentPath): string
    {
        $path = trim($documentPath, '/');

        return sprintf(
            'https://firestore.googleapis.com/v1/projects/%s/databases/%s/documents/%s',
            rawurlencode($this->firebaseCredentials->projectId()),
            rawurlencode($this->firebaseCredentials->firestoreDatabase()),
            $this->encodePath($path),
        );
    }

    private function runQueryUrl(): string
    {
        return sprintf(
            'https://firestore.googleapis.com/v1/projects/%s/databases/%s/documents:runQuery',
            rawurlencode($this->firebaseCredentials->projectId()),
            rawurlencode($this->firebaseCredentials->firestoreDatabase()),
        );
    }

    private function normalizeDocument(array $doc, ?string $fallbackPath = null): ?array
    {
        $name = (string) ($doc['name'] ?? '');
        $id = '';
        if ($name !== '') {
            $parts = explode('/', $name);
            $id = (string) end($parts);
        }

        $path = $fallbackPath;
        if ($path === null && $name !== '' && str_contains($name, '/documents/')) {
            $path = explode('/documents/', $name, 2)[1] ?? '';
        }

        if ($id === '' || $path === null || trim($path) === '') {
            return null;
        }

        return [
            'id' => $id,
            'path' => $path,
            'fields' => $this->decodeFields(is_array($doc['fields'] ?? null) ? $doc['fields'] : []),
            'createTime' => (string) ($doc['createTime'] ?? ''),
            'updateTime' => (string) ($doc['updateTime'] ?? ''),
        ];
    }

    private function encodePath(string $path): string
    {
        $segments = explode('/', $path);

        return implode('/', array_map(static fn (string $segment) => rawurlencode($segment), $segments));
    }

    private function encodeFields(array $fields): array
    {
        $encoded = [];

        foreach ($fields as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $encoded[$key] = $this->encodeValue($value);
        }

        return $encoded;
    }

    private function encodeValue(mixed $value): array
    {
        if ($value === null) {
            return ['nullValue' => null];
        }

        if ($value instanceof \DateTimeInterface) {
            return ['timestampValue' => $value->format(DATE_ATOM)];
        }

        if (is_bool($value)) {
            return ['booleanValue' => $value];
        }

        if (is_int($value)) {
            return ['integerValue' => (string) $value];
        }

        if (is_float($value)) {
            return ['doubleValue' => $value];
        }

        if (is_string($value)) {
            return ['stringValue' => $value];
        }

        if (is_array($value)) {
            if ($this->isList($value)) {
                return [
                    'arrayValue' => [
                        'values' => array_map(fn ($item) => $this->encodeValue($item), $value),
                    ],
                ];
            }

            return [
                'mapValue' => [
                    'fields' => $this->encodeFields($value),
                ],
            ];
        }

        return ['stringValue' => (string) $value];
    }

    private function decodeFields(array $fields): array
    {
        $decoded = [];

        foreach ($fields as $key => $value) {
            if (!is_string($key) || !is_array($value)) {
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

    private function isList(array $value): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }

        $index = 0;
        foreach ($value as $key => $_) {
            if ($key !== $index) {
                return false;
            }
            $index++;
        }

        return true;
    }
}
