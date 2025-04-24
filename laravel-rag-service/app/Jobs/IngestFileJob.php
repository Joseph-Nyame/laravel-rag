<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Services\DataToVector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class IngestFileJob implements ShouldQueue
{
    use Queueable;

    protected $agent;
    protected $filePath;
    protected $originalFilename;

    public $tries = 3;
    public $timeout = 600; 
    public $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(Agent $agent, string $filePath, string $originalFilename)
    {
        $this->agent = $agent;
        $this->filePath = $filePath;
        $this->originalFilename = $originalFilename;
    }

    /**
     * Execute the job.
     */
    public function handle(DataToVector $dataToVector): void
    {
        try {
            Log::debug("Starting file ingestion job", [
                'agent_id' => $this->agent->id,
                'file' => $this->originalFilename,
                'file_path' => $this->filePath,
            ]);

            Log::debug("Checking file existence", [
                'file_path' => $this->filePath,
                'exists' => file_exists($this->filePath),
                'readable' => is_readable($this->filePath),
            ]);

            if (!file_exists($this->filePath) || !is_readable($this->filePath)) {
                throw new \Exception("File not found or not readable: {$this->filePath}");
            }

            $file = new UploadedFile(
                $this->filePath,
                $this->originalFilename,
                null,
                null,
                true
            );

            Log::debug("Calling DataToVector::ingest", [
                'agent_id' => $this->agent->id,
                'file' => $this->originalFilename,
            ]);

            $count = $dataToVector->ingest($this->agent, $file);

            Log::info("File ingestion job completed", [
                'agent_id' => $this->agent->id,
                'file' => $this->originalFilename,
                'point_count' => $count,
            ]);

            Storage::delete(str_replace(storage_path('app/'), '', $this->filePath));
        } catch (\Throwable $e) {
            Log::error("File ingestion job failed: {$e->getMessage()}", [
                'agent_id' => $this->agent->id,
                'file' => $this->originalFilename,
                'file_path' => $this->filePath,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}