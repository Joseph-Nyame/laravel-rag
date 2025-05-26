<?php

namespace App\Services\MultiAgent;

use App\Models\MultiAgent;
use App\Services\MultiAgent\Communication\BroadcastStrategy;
use App\Services\MultiAgent\Communication\ChainedStrategy;
use App\Services\MultiAgent\Communication\DirectStrategy;
use App\Services\MultiAgent\Context\ContextManager;
use App\Services\MultiAgent\Integration\ResponseIntegrator;
use App\Services\MultiAgent\Patterns\PatternSelector;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the multi-agent query process.
 *
 * Coordinates communication strategies, context management, and response integration
 * to process queries across test agents of any type, addressing agent isolation,
 * relevance filtering, and synthesis limitations. Entry point for the advancedQuery
 * controller.
 */
class Orchestrator
{
    /**
     * The context manager for shared data.
     *
     * @var ContextManager
     */
    protected $contextManager;

    /**
     * The response integrator for synthesizing responses.
     *
     * @var ResponseIntegrator
     */
    protected $responseIntegrator;

    /**
     * The pattern selector for choosing strategies.
     *
     * @var PatternSelector
     */
    protected $patternSelector;

    /**
     * The direct communication strategy.
     *
     * @var DirectStrategy
     */
    protected $directStrategy;

    /**
     * The broadcast communication strategy.
     *
     * @var BroadcastStrategy
     */
    protected $broadcastStrategy;

    /**
     * The chained communication strategy.
     *
     * @var ChainedStrategy
     */
    protected $chainedStrategy;

    /**
     * Create a new Orchestrator instance.
     *
     * @param ContextManager $contextManager The context manager for shared data.
     * @param ResponseIntegrator $responseIntegrator The response integrator for synthesis.
     * @param PatternSelector $patternSelector The pattern selector for strategy selection.
     * @param DirectStrategy $directStrategy The direct strategy.
     * @param BroadcastStrategy $broadcastStrategy The broadcast strategy.
     * @param ChainedStrategy $chainedStrategy The chained strategy.
     */
    public function __construct(
        ContextManager $contextManager,
        ResponseIntegrator $responseIntegrator,
        PatternSelector $patternSelector,
        DirectStrategy $directStrategy,
        BroadcastStrategy $broadcastStrategy,
        ChainedStrategy $chainedStrategy
    ) {
        $this->contextManager = $contextManager;
        $this->responseIntegrator = $responseIntegrator;
        $this->patternSelector = $patternSelector;
        $this->directStrategy = $directStrategy;
        $this->broadcastStrategy = $broadcastStrategy;
        $this->chainedStrategy = $chainedStrategy;
    }

    /**
     * Execute a query across the multi-agent system.
     *
     * Coordinates strategy selection, agent communication, and response integration
     * to produce a synthesized response.
     *
     * @param MultiAgent $multiAgent The multi-agent instance.
     * @param string $prompt The user query.
     * @param string|null $sessionId The session ID for conversation history.
     * @param string $strategy The communication strategy (auto, direct, broadcast, chained).
     * @return array The synthesized response and individual responses.
     */
    public function executeQuery(MultiAgent $multiAgent, string $prompt, ?string $sessionId, string $strategy = 'auto'): array
    {
        try {
            // Initialize context
            $this->contextManager->initialize($multiAgent, $prompt, $sessionId);
            $context = $this->contextManager->getContext();

            // Get agents
            $agents = $multiAgent->get_agents();
            if ($agents->isEmpty()) {
                Log::warning("No agents found for multi-agent ID {$multiAgent->id}");
                return ['error' => 'No agents associated with this multi-agent.'];
            }

            // Select strategy
            $selectedStrategy = match ($strategy) {
                'direct' => $this->directStrategy,
                'broadcast' => $this->broadcastStrategy,
                'chained' => $this->chainedStrategy,
                'auto' => $this->patternSelector->select(
                    $agents,
                    $prompt,
                    $context,
                    $this->directStrategy,
                    $this->broadcastStrategy,
                    $this->chainedStrategy
                ),
                default => $this->directStrategy, // Fallback
            };

            // Execute strategy
            $responses = $selectedStrategy->execute($agents, $prompt, $context, $sessionId);

            // Integrate responses
            return $this->responseIntegrator->integrate($responses, $context);
        } catch (\Exception $e) {
            Log::error("Error executing multi-agent query: " . $e->getMessage(), [
                'exception' => $e,
                'multi_agent_id' => $multiAgent->id,
                'prompt' => $prompt,
                'session_id' => $sessionId,
                'strategy' => $strategy,
            ]);

            return [
                'error' => 'Failed to process query.',
                'message' => $e->getMessage(),
            ];
        }
    }
}