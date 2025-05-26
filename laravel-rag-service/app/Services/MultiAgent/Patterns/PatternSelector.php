<?php

namespace App\Services\MultiAgent\Patterns;

use App\Services\MultiAgent\Communication\BroadcastStrategy;
use App\Services\MultiAgent\Communication\ChainedStrategy;
use App\Services\MultiAgent\Communication\DirectStrategy;
use App\Services\MultiAgent\Communication\StrategyInterface;
use App\Services\MultiAgent\Context\SharedContext;
use Illuminate\Support\Collection;

/**
 * Selects the optimal communication strategy for the multi-agent system.
 *
 * Dynamically chooses a strategy (direct, broadcast, chained) for 'auto' mode
 * based on the prompt, agents, and context, optimizing for relevance and data
 * sharing. Works with test agents of any type.
 */
class PatternSelector
{
    /**
     * Select the best communication strategy.
     *
     * Analyzes the prompt, agents, and context to choose direct, broadcast, or
     * chained strategy.
     *
     * @param Collection $agents The collection of Agent models.
     * @param string $prompt The user query.
     * @param SharedContext $context The shared context for data.
     * @param DirectStrategy $directStrategy The direct strategy instance.
     * @param BroadcastStrategy $broadcastStrategy The broadcast strategy instance.
     * @param ChainedStrategy $chainedStrategy The chained strategy instance.
     * @return StrategyInterface The selected strategy.
     */
    public function select(
        Collection $agents,
        string $prompt,
        SharedContext $context,
        DirectStrategy $directStrategy,
        BroadcastStrategy $broadcastStrategy,
        ChainedStrategy $chainedStrategy
    ): StrategyInterface {
        // Check for relations in context (e.g., Qdrant ref_id, prod_ref)
        $relations = $context->get('relations', []);
        $hasRelations = !empty($relations);

        // Simple heuristic: use chained if relations exist, broadcast for multiple agents, direct otherwise
        if ($hasRelations) {
            return $chainedStrategy; // Chained for data dependencies
        }

        if ($agents->count() > 3) {
            return $broadcastStrategy; // Broadcast for parallel processing with many agents
        }

        return $directStrategy; // Direct for simple or few agents
    }
}