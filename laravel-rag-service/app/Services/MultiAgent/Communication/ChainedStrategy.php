<?php

namespace App\Services\MultiAgent\Communication;

use App\Models\Agent;
use App\Services\MultiAgent\Context\ContextManager;
use App\Services\MultiAgent\Context\SharedContext;
use App\Services\RagService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Chained communication strategy for the multi-agent system.
 *
 * Queries agents sequentially, passing data from one agent to the next to leverage
 * dependencies (e.g., Qdrant relations). Directly addresses agent isolation by
 * enabling data flow between test agents of any type, with filtering and synthesis
 * handled downstream.
 */
class ChainedStrategy implements StrategyInterface
{
    /**
     * The RAG service for querying agents.
     *
     * @var RagService
     */
    protected $ragService;

    /**
     * The context manager for shared data.
     *
     * @var ContextManager
     */
    protected $contextManager;

    /**
     * Create a new ChainedStrategy instance.
     *
     * @param RagService $ragService The RAG service for agent queries.
     * @param ContextManager $contextManager The context manager for shared data.
     */
    public function __construct(RagService $ragService, ContextManager $contextManager)
    {
        $this->ragService = $ragService;
        $this->contextManager = $contextManager;
    }

    /**
     * Execute the query across agents sequentially.
     *
     * Each agent's response is stored in the shared context, available to subsequent
     * agents, enabling data flow (e.g., Qdrant relations like ref_id, prod_ref).
     * Responses are collected for downstream filtering and synthesis.
     *
     * @param Collection $agents The collection of Agent models to query.
     * @param string $prompt The user query to process.
     * @param SharedContext $context The shared context for data sharing.
     * @param string|null $sessionId The session ID for conversation history.
     * @return array An array of agent responses, each containing agent_id, agent_name, response, and raw_details.
     */
    public function execute(Collection $agents, string $prompt, SharedContext $context, ?string $sessionId): array
    {
        $responses = [];

        // Add user prompt to conversation history
        $this->contextManager->addUserPrompt($prompt);

        foreach ($agents as $agent) {
            try {
                // Get conversation history and previous agent data
                $history = $context->get('conversation_history', []);
                $previousData = $context->all(); // Pass all context for chaining

                // Augment prompt with previous data if available
                $augmentedPrompt = $prompt;
                if (!empty($previousData)) {
                    $contextSummary = json_encode($previousData, JSON_PRETTY_PRINT);
                    $augmentedPrompt .= "\n\nPrevious context:\n{$contextSummary}";
                }

                // Query the agent using RagService
                $ragResponse = $this->ragService->chat(
                    agent: $agent,
                    query: $augmentedPrompt,
                    conversationHistory: $history
                );

                $responseText = $ragResponse['response'] ?? 'No response text found.';

                // Prepare response structure
                $response = [
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'response' => $responseText,
                    'raw_details' => $ragResponse,
                ];

                // Update context with agent response
                $this->contextManager->updateFromAgent($agent->id, $response);

                $responses[] = $response;
            } catch (\Exception $e) {
                Log::error("Error querying agent ID {$agent->id} ({$agent->name}): " . $e->getMessage(), [
                    'exception' => $e,
                    'agent_id' => $agent->id,
                    'prompt' => $prompt,
                    'session_id' => $sessionId,
                ]);

                $response = [
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'error' => 'Failed to get response from this agent.',
                    'raw_details' => ['message' => $e->getMessage()],
                ];

                $this->contextManager->updateFromAgent($agent->id, $response);
                $responses[] = $response;
            }
        }

        return $responses;
    }
}