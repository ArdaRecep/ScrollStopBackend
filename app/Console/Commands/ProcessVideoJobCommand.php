<?php

namespace App\Console\Commands;

use App\Services\VideoAdPipelineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessVideoJobCommand extends Command
{
    protected $signature = 'videos:process-job {jobId}';

    protected $description = 'Process a pending video generation job';

    public function handle(VideoAdPipelineService $pipeline): int
    {
        $jobId = trim((string) $this->argument('jobId'));
        if ($jobId === '') {
            $this->error('jobId is required');
            return self::FAILURE;
        }

        try {
            $pipeline->processJob($jobId);
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            Log::warning('Video job process command failed', [
                'jobId' => $jobId,
                'error' => mb_substr((string) $exception->getMessage(), 0, 300),
            ]);

            return self::FAILURE;
        }
    }
}
