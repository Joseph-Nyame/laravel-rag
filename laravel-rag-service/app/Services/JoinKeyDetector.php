<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentRelation;
use Illuminate\Support\Facades\Log;
use App\Repositories\QdrantRepository;

class JoinKeyDetector
{
    protected $qdrantRepository;
    protected $debug = true;

    public function __construct(QdrantRepository $qdrantRepository)
    {
        $this->qdrantRepository = $qdrantRepository;
    }

    public function detectJoinKeys(int $sourceAgentId, int $targetAgentId): ?array
    {
        // Fetch agents
        $sourceAgent = Agent::findOrFail($sourceAgentId);
        $targetAgent = Agent::findOrFail($targetAgentId);

        // Sample 100 points
        $sourcePoints = $this->qdrantRepository->fetchPoint($sourceAgent->vector_collection, 100);
        $targetPoints = $this->qdrantRepository->fetchPoint($targetAgent->vector_collection, 100);

        if ($this->debug) {
            Log::debug("Raw Qdrant response for agent {$sourceAgentId} ({$sourceAgent->vector_collection}): " . json_encode($sourcePoints));
            Log::debug("Raw Qdrant response for agent {$targetAgentId} ({$targetAgent->vector_collection}): " . json_encode($targetPoints));
        }

        // Validate points
        if (!is_array($sourcePoints) || !is_array($targetPoints) || empty($sourcePoints) || empty($targetPoints)) {
            Log::warning("Invalid or empty points for agents {$sourceAgentId} ({$sourceAgent->vector_collection}) or {$targetAgentId} ({$targetAgent->vector_collection}). Check Qdrant data ingestion.");
            return null;
        }

        // Log point counts and sample points
        if ($this->debug) {
            Log::debug("Retrieved " . count($sourcePoints) . " points for agent {$sourceAgentId}, " . count($targetPoints) . " for agent {$targetAgentId}");
            Log::debug("Sample point for agent {$sourceAgentId}: " . json_encode($sourcePoints[0] ?? []));
            Log::debug("Sample point for agent {$targetAgentId}: " . json_encode($targetPoints[0] ?? []));
        }

        // Get and normalize field names
        $sourceFields = $this->getFieldNames($sourcePoints);
        $targetFields = $this->getFieldNames($targetPoints);

        if ($this->debug) {
            Log::debug("Fields for agent {$sourceAgentId}: " . implode(', ', $sourceFields));
            Log::debug("Fields for agent {$targetAgentId}: " . implode(', ', $targetFields));
        }

        if (empty($sourceFields) || empty($targetFields)) {
            Log::warning("No fields found for agents {$sourceAgentId} or {$targetAgentId}. Verify Qdrant payload structure and ingestion.");
            return null;
        }

        // Find candidate field pairs
        $candidatePairs = [];
        foreach ($sourceFields as $sourceKey) {
            foreach ($targetFields as $targetKey) {
                $nameSimilarity = $this->calculateNameSimilarity($sourceKey, $targetKey);
                $candidatePairs[] = [
                    'source_key' => $sourceKey,
                    'target_key' => $targetKey,
                    'name_similarity' => $nameSimilarity,
                ];
                if ($this->debug) {
                    Log::debug("Pair {$sourceKey} → {$targetKey}: Name similarity = {$nameSimilarity}");
                }
            }
        }

        if (empty($candidatePairs)) {
            Log::warning("No candidate field pairs for agents {$sourceAgentId} to {$targetAgentId}");
            return null;
        }

        // Calculate confidence for each pair
        $bestPair = null;
        $bestConfidence = 0.0;

        foreach ($candidatePairs as $pair) {
            $confidence = $this->calculateConfidence(
                $pair['source_key'],
                $pair['target_key'],
                $sourcePoints,
                $targetPoints,
                $pair['name_similarity']
            );
            if ($this->debug) {
                Log::debug("Pair {$pair['source_key']} → {$pair['target_key']}: Confidence = {$confidence}");
            }
            if ($confidence > 0.75 && $confidence > $bestConfidence) {
                $bestPair = $pair;
                $bestConfidence = $confidence;
            }
        }

        if (!$bestPair) {
            Log::warning("No high-confidence pair for agents {$sourceAgentId} to {$targetAgentId}");
            return null;
        }

        return [
            'join_key' => $bestPair['source_key'],
            'confidence' => $bestConfidence,
            'description' => "Suggested join key for {$sourceAgent->name} ({$bestPair['source_key']}) to {$targetAgent->name} ({$bestPair['target_key']})"
        ];
    }

    protected function calculateNameSimilarity(string $sourceField, string $targetField): float
    {
        $sourceField = strtolower(str_replace('_', ' ', $sourceField));
        $targetField = strtolower(str_replace('_', ' ', $targetField));

        if ($sourceField === $targetField) {
            return 1.0;
        }

        $levDistance = levenshtein($sourceField, $targetField);
        $maxLength = max(strlen($sourceField), strlen($targetField));
        if ($maxLength === 0) {
            return 0.0;
        }
        $levSimilarity = 1.0 - ($levDistance / $maxLength);

        $prefixBoost = 0.0;
        if (str_starts_with($sourceField, $targetField) || str_starts_with($targetField, $sourceField)) {
            $prefixBoost = 0.2;
        }

        return min($levSimilarity + $prefixBoost, 1.0);
    }

    protected function calculateConfidence(
        string $sourceKey,
        string $targetKey,
        array $sourcePoints,
        array $targetPoints,
        float $nameSimilarity
    ): float {
        $sourceValues = $this->getFieldValues($sourceKey, $sourcePoints);
        $targetValues = $this->getFieldValues($targetKey, $targetPoints);

        if ($this->debug) {
            Log::debug("Values for {$sourceKey}: " . implode(', ', array_slice($sourceValues, 0, 10)));
            Log::debug("Values for {$targetKey}: " . implode(', ', array_slice($targetValues, 0, 10)));
        }

        $intersection = array_intersect($sourceValues, $targetValues);
        $union = array_unique(array_merge($sourceValues, $targetValues));
        $jaccard = count($union) > 0 ? count($intersection) / count($union) : 0.0;

        $chiSquaredScore = $this->calculateChiSquaredScore($sourceValues, $targetValues);

        $pointCount = min(count($sourcePoints), count($targetPoints));
        $overlapWeight = $pointCount > 50 ? 0.7 : 0.6;
        $nameWeight = $pointCount > 50 ? 0.2 : 0.3;
        $chiWeight = 0.1;

        $confidence = ($jaccard * $overlapWeight) + ($nameSimilarity * $nameWeight) + ($chiSquaredScore * $chiWeight);
        return min($confidence, 1.0);
    }

    protected function calculateChiSquaredScore(array $sourceValues, array $targetValues): float
    {
        $sourceFreq = array_count_values($sourceValues);
        $targetFreq = array_count_values($targetValues);

        $allValues = array_unique(array_merge(array_keys($sourceFreq), array_keys($targetFreq)));
        $observed = [];
        $expected = [];
        $totalSource = count($sourceValues);
        $totalTarget = count($targetValues);

        foreach ($allValues as $value) {
            $sourceCount = $sourceFreq[$value] ?? 0;
            $targetCount = $targetFreq[$value] ?? 0;
            $observed[] = [$sourceCount, $targetCount];
            $expectedCount = ($totalSource * $totalTarget) > 0 ? ($sourceCount + $targetCount) / 2 : 0;
            $expected[] = [$expectedCount, $expectedCount];
        }

        $chiSquared = 0.0;
        foreach ($observed as $i => $obs) {
            foreach ([0, 1] as $j) {
                if ($expected[$i][$j] > 0) {
                    $chiSquared += pow($obs[$j] - $expected[$i][$j], 2) / $expected[$i][$j];
                }
            }
        }

        return max(0.0, 1.0 - min($chiSquared / 10, 1.0));
    }

    protected function getFieldNames(array $points): array
    {
        $fields = [];
        // Normalize input to always be an array of points
        $points = is_array($points) && isset($points['id'], $points['payload']) ? [$points] : $points;

        foreach ($points as $point) {
            $payload = null;
            if (is_array($point)) {
                if (isset($point['payload'])) {
                    $payload = $point['payload'];
                } elseif (isset($point['data'])) {
                    $payload = $point['data'];
                } elseif (isset($point['attributes'])) {
                    $payload = $point['attributes'];
                } else {
                    $payload = $this->findPayload($point);
                }
            } elseif (is_string($point)) {
                $decoded = json_decode($point, true);
                if (is_array($decoded)) {
                    $payload = $this->findPayload($decoded);
                }
            }

            if (is_array($payload)) {
                $fields = array_merge($fields, array_keys($payload));
            } else {
                Log::warning("No payload found in point", ['point' => json_encode($point)]);
            }
        }
        $fields = array_map(fn($field) => strtolower(str_replace('_', ' ', $field)), array_unique($fields));
        Log::debug("Extracted fields: " . implode(', ', $fields));
        return $fields;
    }

    protected function findPayload($data): ?array
    {
        if (!is_array($data)) {
            Log::warning("findPayload received non-array data: " . json_encode($data));
            return null;
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                if (isset($value['payload']) || isset($value['data']) || isset($value['attributes'])) {
                    return $value['payload'] ?? $value['data'] ?? $value['attributes'] ?? $value;
                }
                $nested = $this->findPayload($value);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }
        return null;
    }

    protected function getFieldValues(string $field, array $points): array
    {
        $values = [];
        foreach ($points as $point) {
            $payload = null;
            if (is_array($point)) {
                if (isset($point['payload'])) {
                    $payload = $point['payload'];
                } elseif (isset($point['data'])) {
                    $payload = $point['data'];
                } elseif (isset($point['attributes'])) {
                    $payload = $point['attributes'];
                } else {
                    $payload = $this->findPayload($point);
                }
            } elseif (is_string($point)) {
                $decoded = json_decode($point, true);
                if (is_array($decoded)) {
                    $payload = $this->findPayload($decoded);
                }
            }

            if (is_array($payload) && isset($payload[$field])) {
                $value = is_scalar($payload[$field]) ? (string) $payload[$field] : '';
                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }
        return array_unique($values);
    }

    public function storeSuggestion(int $sourceAgentId, int $targetAgentId, array $suggestion): void
    {
        AgentRelation::updateOrCreate(
            [
                'source_agent_id' => $sourceAgentId,
                'target_agent_id' => $targetAgentId,
                'join_key' => $suggestion['join_key'],
            ],
            [
                'description' => $suggestion['description'],
                'confidence' => $suggestion['confidence'],
            ]
        );
    }
}