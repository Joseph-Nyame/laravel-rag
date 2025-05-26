<?php

namespace App\Services\MultiAgent\Context;

use App\Models\MultiAgent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Manages the shared context for the multi-agent system.
 *
 * Initializes, updates, and retrieves shared context (via SharedContext) to enable
 * data sharing across agents, reducing agent isolation. Supports test agents of any
 * type by handling arbitrary data (e.g., Qdrant relations, agent outputs, session
 * history). Integrates with cache for conversation history persistence.
 */
class ContextManager
{
    /**
     * The shared context instance.
     *
     * @var SharedContext
     */
    protected $context;

    /**
     * Create a new ContextManager instance.
     *
     * @param SharedContext $context The shared context to manage.
     */
    public function __construct(SharedContext $context)
    {
        $this->context = $context;
    }

    /**
     * Initialize the shared context for a query.
     *
     * Sets up initial data based on the multi-agent, prompt, and session ID.
     * Loads session-based conversation history from cache if available.
     *
     * @param MultiAgent $multiAgent The multi-agent instance.
     * @param string $prompt The user query.
     * @param string|null $sessionId The session ID for conversation history, if available.
     * @return void
     */
    public function initialize(MultiAgent $multiAgent, string $prompt, ?string $sessionId): void
    {
        // Clear existing context to start fresh
        $this->context->clear();

        // Store basic query metadata
        $this->context->merge([
            'multi_agent_id' => $multiAgent->id,
            'prompt' => $prompt,
            'session_id' => $sessionId,
        ]);

        // Load session-based conversation history from cache if session ID is provided
        if ($sessionId) {
            $cacheKey = "multi_agent_history_{$multiAgent->id}_{$sessionId}";
            $history = Cache::get($cacheKey, []);
            $this->context->set('conversation_history', $history);
        }

        // Placeholder for initial data (e.g., Qdrant relations)
        // Example: Could load relations like ref_id, prod_ref from Qdrant logs
        $this->context->set('relations', []); // To be expanded based on data source
    }

    /**
     * Update the context with data from an agent.
     *
     * Stores agent output or metadata in the shared context, making it available
     * to other agents or the response integrator.
     *
     * @param string $agentId The ID of the agent providing the data.
     * @param array $data The data to store (e.g., response, metadata).
     * @return void
     */
    public function updateFromAgent(string $agentId, array $data): void
    {
        // Store agent-specific data under a namespaced key
        $this->context->set("agent_{$agentId}_data", $data);

        // Update conversation history if response is included
        if (isset($data['response']) && $this->context->has('conversation_history')) {
            $history = $this->context->get('conversation_history', []);
            $history[] = ['role' => 'assistant', 'content' => $data['response']];
            $this->context->set('conversation_history', $history);

            // Persist updated history to cache if session ID exists
            $sessionId = $this->context->get('session_id');
            if ($sessionId) {
                $cacheKey = "multi_agent_history_{$this->context->get('multi_agent_id')}_{$sessionId}";
                Cache::put($cacheKey, $history, now()->addHours(24));
            }
        }
    }

    /**
     * Get the shared context instance.
     *
     * @return SharedContext The current shared context.
     */
    public function getContext(): SharedContext
    {
        return $this->context;
    }

    /**
     * Add conversation history entry for the user prompt.
     *
     * Updates the conversation history with the user's prompt before agent processing.
     *
     * @param string $prompt The user query.
     * @return void
     */
    public function addUserPrompt(string $prompt): void
    {
        if ($this->context->has('conversation_history')) {
            $history = $this->context->get('conversation_history', []);
            $history[] = ['role' => 'user', 'content' => $prompt];
            $this->context->set('conversation_history', $history);

            // Persist to cache if session ID exists
            $sessionId = $this->context->get('session_id');
            if ($sessionId) {
                $cacheKey = "multi_agent_history_{$this->context->get('multi_agent_id')}_{$sessionId}";
                Cache::put($cacheKey, $history, now()->addHours(24));
            }
        }
    }
}