<?php

namespace App\Services;

use DateTimeImmutable;
use Google\Cloud\Storage\StorageClient;
use RuntimeException;

class FirebaseStorageService
{
    public function __construct(private readonly FirebaseCredentialsService $firebaseCredentials)
    {
    }

    /**
     * @return array{path:string,url:string,urlExpiresAt:string}
     */
    public function uploadVideo(string $localPath, string $uid, string $jobId): array
    {
        if (!is_file($localPath) || filesize($localPath) === 0) {
            throw new RuntimeException('Video output file is missing');
        }

        $projectId = $this->firebaseCredentials->projectId();
        $bucketName = $this->firebaseCredentials->storageBucket();
        $storagePath = sprintf('videos/%s/%s.mp4', trim($uid), trim($jobId));

        $storage = new StorageClient([
            'projectId' => $projectId,
            'keyFile' => $this->firebaseCredentials->credentials(),
        ]);

        $bucketCandidates = $this->buildBucketCandidates($bucketName, $projectId);
        $lastError = null;
        $usedBucket = '';
        $object = null;

        foreach ($bucketCandidates as $candidate) {
            try {
                $bucket = $storage->bucket($candidate);
                $object = $bucket->upload(fopen($localPath, 'r'), [
                    'name' => $storagePath,
                    'metadata' => [
                        'contentType' => 'video/mp4',
                        'cacheControl' => 'private, max-age=0, no-transform',
                    ],
                ]);
                $usedBucket = $candidate;
                break;
            } catch (\Throwable $exception) {
                $lastError = $exception;
                if (!$this->isBucketNotFoundError($exception->getMessage())) {
                    throw new RuntimeException(
                        'Firebase storage upload failed: '.$this->sanitizeStorageError($exception->getMessage())
                    );
                }
            }
        }

        if (!$object) {
            $reason = $lastError ? $this->sanitizeStorageError($lastError->getMessage()) : 'unknown error';
            throw new RuntimeException(
                'Firebase storage bucket not found. Tried: '.implode(', ', $bucketCandidates).'. Last error: '.$reason
            );
        }

        $expiresAt = new DateTimeImmutable('+7 days');
        $signedUrl = $object->signedUrl($expiresAt);

        return [
            'path' => $storagePath,
            'url' => $signedUrl,
            'urlExpiresAt' => $expiresAt->format(DATE_ATOM),
            'bucket' => $usedBucket,
        ];
    }

    private function buildBucketCandidates(string $configuredBucket, string $projectId): array
    {
        $candidates = [];

        $add = static function (string $value) use (&$candidates): void {
            $normalized = trim($value);
            if ($normalized === '') {
                return;
            }

            if (!in_array($normalized, $candidates, true)) {
                $candidates[] = $normalized;
            }
        };

        $add($configuredBucket);

        if (str_ends_with($configuredBucket, '.firebasestorage.app')) {
            $add(preg_replace('/\.firebasestorage\.app$/', '.appspot.com', $configuredBucket) ?: '');
        }

        if (str_ends_with($configuredBucket, '.appspot.com')) {
            $add(preg_replace('/\.appspot\.com$/', '.firebasestorage.app', $configuredBucket) ?: '');
        }

        $add($projectId.'.appspot.com');
        $add($projectId.'.firebasestorage.app');

        return $candidates;
    }

    private function isBucketNotFoundError(string $message): bool
    {
        $lower = strtolower($message);
        return str_contains($lower, 'bucket does not exist')
            || str_contains($lower, 'bucket not found')
            || str_contains($lower, 'notfound')
            || str_contains($lower, 'reason\": \"notfound\"');
    }

    private function sanitizeStorageError(string $message): string
    {
        $clean = trim($message);
        if ($clean === '') {
            return 'unknown error';
        }

        return mb_substr($clean, 0, 220);
    }
}
