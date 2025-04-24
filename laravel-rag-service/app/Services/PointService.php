<?php

namespace App\Services;

use App\Models\Agent;
use App\Repositories\Interfaces\QdrantRepositoryInterface;
use Illuminate\Http\UploadedFile;

class PointService
{
    protected QdrantRepositoryInterface $qdrantRepository;
    protected VectorService $vectorService;

    public function __construct(QdrantRepositoryInterface $qdrantRepository, VectorService $vectorService)
    {
        $this->qdrantRepository = $qdrantRepository;
        $this->vectorService = $vectorService;
    }

    public function createPoints(Agent $agent, UploadedFile $file, array $items): array
    {
        return $this->vectorService->createPoint($agent, $file, $items);
    }

    public function upsertPoints(Agent $agent, array $points): bool
    {
        return $this->qdrantRepository->upsertPoints($agent->vector_collection, $points);
    }

    public function deletePoint(Agent $agent, string $pointId): bool
    {
        return $this->qdrantRepository->deletePoint($agent->vector_collection, $pointId);
    }
}