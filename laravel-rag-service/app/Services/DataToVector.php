<?php

namespace App\Services;

use App\Models\Agent;
use App\Services\PointService;
use App\Services\FileProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class DataToVector
{
    protected FileProcessor $fileProcessor;
    protected PointService $pointService;

    public function __construct(FileProcessor $fileProcessor, PointService $pointService)
    {
        $this->fileProcessor = $fileProcessor;
        $this->pointService = $pointService;
    }

    public function ingest(Agent $agent, UploadedFile $file): int
    {
        try {
            $dataItems = $this->fileProcessor->processFile($file);
            if (empty($dataItems)) {
                Log::info('No data extracted from file', ['file' => $file->getClientOriginalName()]);
                throw new \Exception('No data extracted from file.');
            }

            $points = $this->pointService->createPoints($agent, $file, $dataItems);
            $this->pointService->upsertPoints($agent, $points);

            Log::info('Ingestion successful', [
                'collection' => $agent->vector_collection,
                'points_count' => count($points),
                'file' => $file->getClientOriginalName(),
            ]);

            return count($dataItems);
        } catch (\Exception $e) {
            Log::error("DataToVector ingest failed for agent {$agent->id}", [
                'error' => $e->getMessage(),
                'file' => $file->getClientOriginalName(),
            ]);
            throw $e;
        }
    }
}