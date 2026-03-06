<?php

namespace App\Services;

use RuntimeException;

class FirebaseCredentialsService
{
    private ?array $credentials = null;

    public function credentials(): array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $b64 = config('firebase.credentials_b64');
        if (!is_string($b64) || trim($b64) === '') {
            throw new RuntimeException('FIREBASE_CREDENTIALS_B64 missing');
        }

        $decoded = base64_decode($b64, true);
        if ($decoded === false) {
            throw new RuntimeException('FIREBASE_CREDENTIALS_B64 invalid base64');
        }

        $parsed = json_decode($decoded, true);
        if (!is_array($parsed)) {
            throw new RuntimeException('Firebase credentials JSON invalid');
        }

        $this->credentials = $parsed;

        return $this->credentials;
    }

    public function projectId(): string
    {
        $projectId = trim((string) (config('firebase.project_id') ?: ($this->credentials()['project_id'] ?? '')));

        if ($projectId === '') {
            throw new RuntimeException('Firebase project_id missing in credentials');
        }

        return $projectId;
    }

    public function firestoreDatabase(): string
    {
        return trim((string) config('firebase.firestore_database', '(default)')) ?: '(default)';
    }

    public function storageBucket(): string
    {
        $bucket = trim((string) (config('firebase.storage_bucket') ?: env('FIREBASE_STORAGE_BUCKET', '')));

        if ($bucket !== '') {
            return $bucket;
        }

        return $this->projectId().'.appspot.com';
    }
}
