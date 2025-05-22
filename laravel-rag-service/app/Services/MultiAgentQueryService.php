<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\MultiAgent;
use App\Services\RagService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str; // For Str::uuid if needed for session IDs without global one

class MultiAgentQueryService
{
    protected RagService $ragService;

    public function __construct(RagService $ragService)
    {
        $this->ragService = $ragService;
    }

    public function process_query(MultiAgent $multiAgent, string $prompt, ?string $globalSessionId): array
    {
        $agents = $multiAgent->get_agents();

        if ($agents->isEmpty()) {
            return ['error' => 'No agents associated with this multi-agent or agents could not be retrieved.'];
        }

        $detailedIndividualResponses = [];
        $synthesizedResponseParts = [];

        foreach ($agents as $agent) {
            try {
                // Generate agent-specific session ID
                $agentSpecificSessionId = $globalSessionId 
                    ? "{$globalSessionId}_agent_{$agent->id}" 
                    : "agent_{$agent->id}_" . Str::uuid()->toString(); // Fallback if no global session ID

                $cacheKey = "chat_history_{$agent->id}_{$agentSpecificSessionId}";
                $conversationHistory = Cache::get($cacheKey, []);

                // Call RAG service
                $ragResponse = $this->ragService->chat(
                    agent: $agent,
                    query: $prompt,
                    conversationHistory: $conversationHistory
                );

                $responseText = $ragResponse['response'] ?? 'No response text found.';

                // Store detailed response
                $detailedIndividualResponses[] = [
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'response' => $responseText,
                    'raw_details' => $ragResponse, // Store the full response from ragService
                ];

                // For synthesis
                $synthesizedResponseParts[] = "Response from {$agent->name}: {$responseText}";

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
                // Add error information to the responses if needed, or just log and continue
                $detailedIndividualResponses[] = [
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'error' => 'Failed to get response from this agent.',
                    'raw_details' => ['message' => $e->getMessage()],
                ];
                // Optionally add to synthesized response too, or skip this agent's part
                 $synthesizedResponseParts[] = "Error from {$agent->name}: Could not retrieve response.";
            }
        }

        $synthesizedResponse = implode("\n", $synthesizedResponseParts);

        return [
            'synthesized_response' => $synthesizedResponse,
            'individual_responses' => $detailedIndividualResponses,
            // 'session_id' => $globalSessionId, // The controller will add session_id to the final response
        ];
    }
}
