<?php

namespace App\Services\MultiAgent\Communication;

use App\Models\Agent;
use App\Services\MultiAgent\Context\SharedContext;
use Illuminate\Support\Collection;

/**
 * Interface for communication strategies in the multi-agent system.
 *
 * Defines the contract for how agents process a query, allowing different
 * strategies (e.g., direct, broadcast, chained) to handle agent communication.
 * This ensures flexibility for test agents of any type and supports data sharing
 * to reduce agent isolation.
 */
interface StrategyInterface
{
    /**
     * Execute the query across agents using the specified strategy.
     *
     * @param Collection $agents The collection of Agent models to query.
     * @param string $prompt The user query to process.
     * @param SharedContext $context The shared context for data sharing between agents.
     * @param string|null $sessionId The session ID for conversation history, if available.
     * @return array An array of agent responses, each containing agent_id, agent_name, response, and raw_details.
     */
    public function execute(Collection $agents, string $prompt, SharedContext $context, ?string $sessionId): array;
}