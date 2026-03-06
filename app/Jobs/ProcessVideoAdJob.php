<?php

namespace App\Jobs;

use App\Services\VideoAdPipelineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessVideoAdJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $jobId)
    {
    }

    public function handle(VideoAdPipelineService $pipeline): void
    {
        $pipeline->processJob($this->jobId);
    }
}
