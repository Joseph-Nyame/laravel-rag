<?php

namespace App\Repositories\Interfaces;

interface QdrantRepositoryInterface
{
    public function checkCollection(string $collection): bool;

    public function upsertPoints(string $collection, array $points): bool;

    public function deletePoint(string $collection, string $pointId): bool;
}