<?php

namespace App\Repositories;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Repositories\Interfaces\QdrantRepositoryInterface;

class QdrantRepository implements QdrantRepositoryInterface
{
    protected string $vectorDbUrl;

    public function __construct()
    {
        $this->vectorDbUrl = config('services.qdrant.host');
    }

    public function checkCollection(string $collection): bool
    {
        try {
            $response = Http::get("{$this->vectorDbUrl}/collections/{$collection}");
            if ($response->failed() || $response->json('result.status') !== 'green') {
                Log::error('Qdrant collection not found', [
                    'collection' => $collection,
                    'error' => $response->json('status.error', 'Unknown error'),
                ]);
                return false;
            }
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to check Qdrant collection: {$e->getMessage()}", [
                'collection' => $collection,
            ]);
            return false;
        }
    }

    public function upsertPoints(string $collection, array $points): bool
    {
        try {
            if (!$this->checkCollection($collection)) {
                throw new \Exception("Collection {$collection} does not exist.");
            }

            $response = Http::put("{$this->vectorDbUrl}/collections/{$collection}/points?wait=true", [
                'points' => $points,
            ]);

            if ($response->failed() || $response->json('result.status') !== 'completed') {
                Log::error('Failed to upsert points', [
                    'collection' => $collection,
                    'error' => $response->json('status.error', 'Unknown error'),
                    'response' => $response->body(),
                ]);
                throw new \Exception("Failed to upsert points.");
            }

            Log::info('Upsert successful', [
                'collection' => $collection,
                'points_count' => count($points),
            ]);
            return true;
        } catch (\Exception $e) {
            Log::error("Upsert points failed: {$e->getMessage()}", [
                'collection' => $collection,
            ]);
            throw $e;
        }
    }

    public function deletePoint(string $collection, string $pointId): bool
    {
        try {
            if (!$this->checkCollection($collection)) {
                throw new \Exception("Collection {$collection} does not exist.");
            }

            $response = Http::post("{$this->vectorDbUrl}/collections/{$collection}/points/delete?wait=true", [
                'points' => [$pointId],
            ]);

            if ($response->failed() || $response->json('result.status') !== 'completed') {
                Log::error('Failed to delete point', [
                    'collection' => $collection,
                    'point_id' => $pointId,
                    'error' => $response->json('status.error', 'Unknown error'),
                ]);
                throw new \Exception("Failed to delete point {$pointId}.");
            }

            Log::info('Point deleted successfully', [
                'collection' => $collection,
                'point_id' => $pointId,
            ]);
            return true;
        } catch (\Exception $e) {
            Log::error("Delete point failed: {$e->getMessage()}", [
                'collection' => $collection,
                'point_id' => $pointId,
            ]);
            throw $e;
        }
    }

    public function fetchPoint(string $collection): ?array
    {
        try {
            $response = Http::post("{$this->vectorDbUrl}/collections/{$collection}/points/scroll", [
                'limit' => 1,
                'with_payload' => true,
                'with_vector' => false,
            ]);

            if ($response->successful()) {
                $points = $response->json()['result']['points'] ?? [];
                return !empty($points) ? $points[0] : null;
            }
        } catch (\Exception $e) {
            Log::error('Failed to fetch Qdrant point', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
        }
        return null;
    }

    public function insertPoint(string $collection, string $pointId, array $payload): bool
    {
        try {
            if (!$this->checkCollection($collection)) {
                throw new \Exception("Collection {$collection} does not exist.");
            }

            $point = [
                'id' => $pointId,
                'payload' => $payload,
                // Assuming no vector is needed for metadata-only points
            ];

            $response = Http::put("{$this->vectorDbUrl}/collections/{$collection}/points?wait=true", [
                'points' => [$point],
            ]);

            if ($response->failed() || $response->json('result.status') !== 'completed') {
                Log::error('Failed to insert point', [
                    'collection' => $collection,
                    'point_id' => $pointId,
                    'error' => $response->json('status.error', 'Unknown error'),
                    'response' => $response->body(),
                ]);
                throw new \Exception("Failed to insert point {$pointId}.");
            }

            Log::info('Point inserted successfully', [
                'collection' => $collection,
                'point_id' => $pointId,
            ]);
            return true;
        } catch (\Exception $e) {
            Log::error("Insert point failed: {$e->getMessage()}", [
                'collection' => $collection,
                'point_id' => $pointId,
            ]);
            throw $e;
        }
    }

    public function searchPoints(string $collection, array $filters, int $limit): array
    {
        try {
            if (!$this->checkCollection($collection)) {
                throw new \Exception("Collection {$collection} does not exist.");
            }

            $response = Http::post("{$this->vectorDbUrl}/collections/{$collection}/points/scroll", [
                'filter' => $filters,
                'limit' => $limit,
                'with_payload' => true,
                'with_vector' => false,
            ]);

            if ($response->failed()) {
                Log::error('Failed to search points', [
                    'collection' => $collection,
                    'filters' => $filters,
                    'error' => $response->json('status.error', 'Unknown error'),
                    'response' => $response->body(),
                ]);
                throw new \Exception("Failed to search points in {$collection}.");
            }

            $points = $response->json()['result']['points'] ?? [];
            Log::debug('Qdrant search results', [
                'collection' => $collection,
                'filters' => $filters,
                'points_count' => count($points),
            ]);
            return $points;
        } catch (\Exception $e) {
            Log::error("Search points failed: {$e->getMessage()}", [
                'collection' => $collection,
                'filters' => $filters,
            ]);
            throw $e;
        }
    }
}