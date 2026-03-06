<?php

namespace App\Services;

use App\Jobs\ProcessVideoAdJob;

class VideoJobDispatcher
{
    public function __construct(private readonly VideoAdPipelineService $pipeline)
    {
    }

    public function dispatch(string $jobId): void
    {
        $mode = strtolower(trim((string) config('video.dispatch_mode', 'process')));

        if ($mode === 'sync') {
            $this->pipeline->processJob($jobId);
            return;
        }

        if ($mode === 'queue') {
            ProcessVideoAdJob::dispatch($jobId);
            return;
        }

        $this->dispatchDetachedProcess($jobId);
    }

    private function dispatchDetachedProcess(string $jobId): void
    {
        $phpBinary = escapeshellarg(PHP_BINARY ?: 'php');
        $artisan = escapeshellarg(base_path('artisan'));
        $argJobId = escapeshellarg($jobId);
        $logPath = escapeshellarg('/tmp/video-job-'.$jobId.'.log');

        $command = sprintf(
            'nohup %s %s videos:process-job %s > %s 2>&1 &',
            $phpBinary,
            $artisan,
            $argJobId,
            $logPath,
        );

        exec($command);
    }
}
