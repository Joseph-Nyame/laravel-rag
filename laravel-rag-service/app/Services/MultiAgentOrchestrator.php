<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\MultiAgent;
use App\Jobs\DetectJoinKeysJob;
use App\Models\MultiAgentRelation;
use App\Repositories\QdrantRepository;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MultiAgentOrchestrator
{
    protected $qdrantRepository;
    protected $joinKeyDetector;
    protected $debug = true;

    public function __construct(QdrantRepository $qdrantRepository, JoinKeyDetector $joinKeyDetector)
    {
        $this->qdrantRepository = $qdrantRepository;
        $this->joinKeyDetector = $joinKeyDetector;
    }

    public function createMultiAgent(array $data): MultiAgent
    {
        // Validate input
        $validator = Validator::make($data, [
            'name' => 'required|string|max:100',
            'agent_ids' => 'required|array|min:2|max:10',
            'agent_ids.*' => 'integer|exists:agents,id',
            'relations' => 'sometimes|array|min:1',
            'relations.*.source_agent_id' => 'required|integer|exists:agents,id|in_array:agent_ids.*',
            'relations.*.target_agent_id' => 'required|integer|exists:agents,id|in_array:agent_ids.*|different:relations.*.source_agent_id',
            'relations.*.join_key' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Suggest name if blank or improve case
        $name = $data['name'];
        if (empty(trim($name))) {
            $name = $this->suggestName($data['agent_ids']);
        } else {
            $name = ucwords(str_replace('_', ' ', $name));
        }

        // Validate unique name (case-insensitive)
        if (MultiAgent::whereRaw('LOWER(name) = ?', [strtolower($name)])->exists()) {
            throw ValidationException::withMessages(['name' => 'The name is already taken.']);
        }

        // // Use provided relations or auto-detect
        // $relations = $data['relations'] ?? $this->detectRelations($data['agent_ids']);

        // if (empty($relations)) {
        //     Log::error('Failed to detect relations for agents: ' . implode(',', $data['agent_ids']) . '. Check Qdrant data ingestion and payload structure.');
        //     throw new ValidationException(
        //         Validator::make([], [], ['relations' => 'Could not detect valid relations for the provided agents. Please provide manual relations or verify Qdrant data ingestion.'])
        //     );
        // }

        // if ($this->debug) {
        //     Log::debug('Detected relations: ' . json_encode($relations));
        // }

        // Create multi-agent
        $multiAgent = MultiAgent::create([
            'name' => $name,
            'agent_ids' => $data['agent_ids'],
        ]);
        return $multiAgent;
    }

    protected function suggestName(array $agentIds): string
    {
        $agentNames = Agent::whereIn('id', $agentIds)
            ->pluck('name')
            ->map(fn($name) => str_replace(' ', '', ucwords($name)))
            ->implode('');
        return 'MultiAgent_' . $agentNames . '_' . now()->format('YmdHis');
    }

    // protected function detectRelations(array $agentIds): array
    // {
    //     $n = count($agentIds);
    //     $pairScores = [];

    //     // Generate all possible pairs
    //     for ($i = 0; $i < $n; $i++) {
    //         for ($j = 0; $j < $n; $j++) {
    //             if ($i !== $j) {
    //                 $suggestion = $this->joinKeyDetector->detectJoinKeys($agentIds[$i], $agentIds[$j]);
    //                 if ($suggestion && $suggestion['confidence'] > 0.75) {
    //                     $pairScores[] = [
    //                         'source' => $agentIds[$i],
    //                         'target' => $agentIds[$j],
    //                         'join_key' => $suggestion['join_key'],
    //                         'confidence' => $suggestion['confidence'],
    //                         'description' => $suggestion['description'],
    //                     ];
    //                 }
    //             }
    //         }
    //     }

    //     if (empty($pairScores)) {
    //         Log::warning('No valid relations detected for agents: ' . implode(',', $agentIds));
    //         return [];
    //     }

    //     // Find the best chain
    //     $bestChain = [];
    //     $bestTotalConfidence = 0.0;
        
    //     foreach ($agentIds as $startAgent) {
    //         $currentChain = [];
    //         $usedAgents = [$startAgent];
    //         $currentAgent = $startAgent;
    //         $totalConfidence = 0.0;

    //         for ($i = 0; $i < $n - 1; $i++) {
    //             $bestPair = null;
    //             $bestConfidence = 0.0;

    //             foreach ($pairScores as $pair) {
    //                 if ($pair['source'] === $currentAgent && !in_array($pair['target'], $usedAgents)) {
    //                     if ($pair['confidence'] > $bestConfidence) {
    //                         $bestPair = $pair;
    //                         $bestConfidence = $pair['confidence'];
    //                     }
    //                 }
    //             }

    //             if (!$bestPair) {
    //                 break;
    //             }

    //             $currentChain[] = [
    //                 'source_agent_id' => $bestPair['source'],
    //                 'target_agent_id' => $bestPair['target'],
    //                 'join_key' => $bestPair['join_key'],
    //                 'description' => $bestPair['description'],
    //             ];
    //             $totalConfidence += $bestConfidence;
    //             $usedAgents[] = $bestPair['target'];
    //             $currentAgent = $bestPair['target'];
    //         }

    //         if (!empty($currentChain) && $totalConfidence > $bestTotalConfidence) {
    //             $bestChain = $currentChain;
    //             $bestTotalConfidence = $totalConfidence;
    //         }
    //     }

    //     if (empty($bestChain)) {
    //         Log::warning('No valid chain found for agents: ' . implode(',', $agentIds));
    //     } else if (count($bestChain) < $n - 1) {
    //         Log::warning('Partial chain detected for agents: ' . implode(',', $agentIds) . ', relations: ' . json_encode($bestChain));
    //     }

    //     return $bestChain;
    // }
}