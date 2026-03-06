<?php

namespace App\Services;

use RuntimeException;

class FirestoreJobService
{
    private const JOB_COLLECTION = 'ai_jobs';

    public function __construct(private readonly FirestoreService $firestore)
    {
    }

    public function createVideoJob(string $jobId, string $uid, array $inputPayload): void
    {
        $now = gmdate('c');

        $this->firestore->createDocument(self::JOB_COLLECTION, $jobId, [
            'userId' => $uid,
            'jobType' => 'video',
            'status' => 'pending',
            'inputPayload' => $inputPayload,
            'outputPayload' => [],
            'createdAt' => $now,
            'updatedAt' => $now,
            'completedAt' => null,
            'videoUrl' => null,
            'errorMessage' => null,
        ]);
    }

    public function markProcessing(string $jobId): void
    {
        $this->updateStatus($jobId, 'processing', [
            'updatedAt' => gmdate('c'),
            'errorMessage' => null,
        ]);
    }

    public function markSuccess(string $jobId, string $videoUrl, array $outputPayload = []): void
    {
        $now = gmdate('c');

        $this->updateStatus($jobId, 'success', [
            'videoUrl' => $videoUrl,
            'outputPayload' => $outputPayload,
            'updatedAt' => $now,
            'completedAt' => $now,
            'errorMessage' => null,
        ]);
    }

    public function markError(string $jobId, string $message, array $outputPayload = []): void
    {
        $safeMessage = trim($message);
        if ($safeMessage === '') {
            $safeMessage = 'Video generation failed';
        }

        $this->updateStatus($jobId, 'error', [
            'errorMessage' => mb_substr($safeMessage, 0, 500),
            'outputPayload' => $outputPayload,
            'updatedAt' => gmdate('c'),
            'completedAt' => gmdate('c'),
            'videoUrl' => null,
        ]);
    }

    public function getJob(string $jobId): ?array
    {
        $doc = $this->firestore->getDocument($this->jobPath($jobId));
        if (!$doc) {
            return null;
        }

        $fields = is_array($doc['fields'] ?? null) ? $doc['fields'] : [];

        return [
            'id' => (string) ($doc['id'] ?? $jobId),
            'userId' => (string) ($fields['userId'] ?? ''),
            'jobType' => (string) ($fields['jobType'] ?? ''),
            'status' => (string) ($fields['status'] ?? 'pending'),
            'inputPayload' => is_array($fields['inputPayload'] ?? null) ? $fields['inputPayload'] : [],
            'outputPayload' => is_array($fields['outputPayload'] ?? null) ? $fields['outputPayload'] : [],
            'createdAt' => (string) ($fields['createdAt'] ?? ($doc['createTime'] ?? '')),
            'updatedAt' => (string) ($fields['updatedAt'] ?? ($doc['updateTime'] ?? '')),
            'completedAt' => is_string($fields['completedAt'] ?? null) ? $fields['completedAt'] : null,
            'videoUrl' => is_string($fields['videoUrl'] ?? null) ? $fields['videoUrl'] : null,
            'errorMessage' => is_string($fields['errorMessage'] ?? null) ? $fields['errorMessage'] : null,
        ];
    }

    public function listUserVideoJobs(string $uid, int $limit = 20): array
    {
        $normalizedUid = trim($uid);
        if ($normalizedUid === '') {
            return [];
        }

        $safeLimit = max(1, min(50, $limit));
        $documents = $this->firestore->runStructuredQuery([
            'from' => [
                ['collectionId' => self::JOB_COLLECTION],
            ],
            'where' => [
                'fieldFilter' => [
                    'field' => ['fieldPath' => 'userId'],
                    'op' => 'EQUAL',
                    'value' => ['stringValue' => $normalizedUid],
                ],
            ],
            'limit' => max(10, $safeLimit * 2),
        ]);

        $items = [];
        foreach ($documents as $doc) {
            if (!is_array($doc)) {
                continue;
            }

            $fields = is_array($doc['fields'] ?? null) ? $doc['fields'] : [];
            if ((string) ($fields['jobType'] ?? '') !== 'video') {
                continue;
            }

            $items[] = [
                'id' => (string) ($doc['id'] ?? ''),
                'userId' => (string) ($fields['userId'] ?? ''),
                'jobType' => (string) ($fields['jobType'] ?? ''),
                'status' => (string) ($fields['status'] ?? 'pending'),
                'inputPayload' => is_array($fields['inputPayload'] ?? null) ? $fields['inputPayload'] : [],
                'outputPayload' => is_array($fields['outputPayload'] ?? null) ? $fields['outputPayload'] : [],
                'createdAt' => (string) ($fields['createdAt'] ?? ($doc['createTime'] ?? '')),
                'updatedAt' => (string) ($fields['updatedAt'] ?? ($doc['updateTime'] ?? '')),
                'completedAt' => is_string($fields['completedAt'] ?? null) ? $fields['completedAt'] : null,
                'videoUrl' => is_string($fields['videoUrl'] ?? null) ? $fields['videoUrl'] : null,
                'errorMessage' => is_string($fields['errorMessage'] ?? null) ? $fields['errorMessage'] : null,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $aTime = strtotime((string) ($a['createdAt'] ?? '')) ?: 0;
            $bTime = strtotime((string) ($b['createdAt'] ?? '')) ?: 0;
            return $bTime <=> $aTime;
        });

        return array_slice($items, 0, $safeLimit);
    }

    private function updateStatus(string $jobId, string $status, array $extra = []): void
    {
        $payload = array_merge($extra, ['status' => $status]);
        $this->firestore->updateDocument($this->jobPath($jobId), $payload, array_keys($payload));
    }

    private function jobPath(string $jobId): string
    {
        $normalized = trim($jobId);
        if ($normalized === '') {
            throw new RuntimeException('jobId is empty');
        }

        return self::JOB_COLLECTION.'/'.$normalized;
    }
}
