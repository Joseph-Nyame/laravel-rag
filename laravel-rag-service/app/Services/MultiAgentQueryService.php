<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\MultiAgent;
use App\Services\RagService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MultiAgentQueryService
{
    protected RagService $ragService;

    public function __construct(RagService $ragService)
    {
        $this->ragService = $ragService;
    }

    /**
     * Check if a response is relevant and impactful for the prompt.
     *
     * @param array $response The agent's response array
     * @param string $responseText The text content of the response
     * @return bool
     */
    protected function isResponseRelevant(array $response, string $responseText): bool
    {
        // Check for errors or missing response
        if (isset($response['error']) || empty($responseText) || $responseText === 'No response text found.') {
            return false;
        }

        // Check for common phrases indicating lack of data or irrelevance
        $irrelevantPhrases = [
            'cannot provide',
            'no data',
            'not enough information',
            'contact support',
            'unable to answer',
            'no response text found',
            'no relevant',
        ];

        foreach ($irrelevantPhrases as $phrase) {
            if (stripos($responseText, $phrase) !== false) {
                return false;
            }
        }

        // Basic check for meaningful content (e.g., response has some length and isn't just a generic message)
        return strlen(trim($responseText)) > 20; // Arbitrary threshold to filter out very short, generic responses
    }

    public function process_query(MultiAgent $multiAgent, string $prompt, ?string $globalSessionId): array
    {
        $agents = $multiAgent->get_agents();

        if ($agents->isEmpty()) {
            return ['error' => 'No agents associated with this multi-agent or agents could not be retrieved.'];
        }

        $detailedIndividualResponses = [];
        $relevantResponseParts = [];

        foreach ($agents as $agent) {
            try {
                // Generate agent-specific session ID
                $agentSpecificSessionId = $globalSessionId
                    ? "{$globalSessionId}_agent_{$agent->id}"
                    : "agent_{$agent->id}_" . Str::uuid()->toString();

                $cacheKey = "chat_history_{$agent->id}_{$agentSpecificSessionId}";
                $conversationHistory = Cache::get($cacheKey, []);

                // Call RAG service
                $ragResponse = $this->ragService->chat(
                    agent: $agent,
                    query: $prompt,
                    conversationHistory: $conversationHistory
                );

                $responseText = $ragResponse['response'] ?? 'No response text found.';

                // Store detailed response (always, for debugging/logging)
                $detailedIndividualResponses[] = [
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'response' => $responseText,
                    'raw_details' => $ragResponse,
                ];

                // Check if response is relevant before adding to synthesis
                if ($this->isResponseRelevant($ragResponse, $responseText)) {
                    $relevantResponseParts[] = [
                        'agent_name' => $agent->name,
                        'response' => $responseText,
                    ];
                } else {
                    Log::info("Excluded irrelevant response from agent {$agent->name} (ID: {$agent->id}): {$responseText}");
                }

                // Update conversation history in cache
                $conversationHistory[] = ['role' => 'user', 'content' => $prompt];
                $conversationHistory[] = ['role' => 'assistant', 'content' => $responseText];
                Cache::put($cacheKey, $conversationHistory, now()->addHours(24));

            } catch (\Exception $e) {
                Log::error("Error querying agent ID {$agent->id} ({$agent->name}) for MultiAgent ID {$multiAgent->id}: " . $e->getMessage(), [
                    'exception' => $e,
                    'multi_agent_id' => $multiAgent->id,
                    'agent_id' => $agent->id,
                    'prompt' => $prompt,
                    'global_session_id' => $globalSessionId,
                ]);
                // Store error in detailed responses
                $detailedIndividualResponses[] = [
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'error' => 'Failed to get response from this agent.',
                    'raw_details' => ['message' => $e->getMessage()],
                ];
                // Skip error responses for synthesis (handled by isResponseRelevant)
            }
        }

        // Create a readable synthesized response
        $synthesizedResponse = '';
        if (!empty($relevantResponseParts)) {
            $synthesizedResponse .= "Summary of relevant responses:\n\n";
            foreach ($relevantResponseParts as $part) {
                $synthesizedResponse .= "- **{$part['agent_name']}**: {$part['response']}\n\n";
            }
        } else {
            $synthesizedResponse = "No relevant responses were found for your query.";
            Log::warning("No relevant responses for prompt: {$prompt}", [
                'multi_agent_id' => $multiAgent->id,
                'global_session_id' => $globalSessionId,
            ]);
        }

        return [
            'synthesized_response' => $synthesizedResponse,
            'individual_responses' => $detailedIndividualResponses,
        ];
    }
}