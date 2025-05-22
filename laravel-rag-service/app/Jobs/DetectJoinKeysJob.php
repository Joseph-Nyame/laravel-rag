<?php

namespace App\Jobs;

use App\Services\JoinKeyDetector;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
class DetectJoinKeysJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $sourceAgentId;
    protected $targetAgentId;

    public function __construct(int $sourceAgentId, int $targetAgentId)
    {
        $this->sourceAgentId = $sourceAgentId;
        $this->targetAgentId = $targetAgentId;
    }

    public function handle(JoinKeyDetector $detector): void
    {
        $suggestion = $detector->detectJoinKeys($this->sourceAgentId, $this->targetAgentId);
        if ($suggestion) {
            $detector->storeSuggestion($this->sourceAgentId, $this->targetAgentId, $suggestion);
        }
    }

}
