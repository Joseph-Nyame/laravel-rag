<?php

namespace App\Services\MultiAgent\Integration;

use App\Models\MultiAgent;
use App\Services\MultiAgent\Context\SharedContext;
use Illuminate\Support\Facades\Log;
use OpenAI;

/**
 * Integrates and synthesizes agent responses in the multi-agent system.
 *
 * Filters irrelevant responses and refines them into a unified, intuitive output
 * using OpenAI's gpt-4o-mini model, attributing to the MultiAgent's name. Works
 * generically with any agent type and response content, using shared context for
 * additional data.
 */
class ResponseIntegrator
{
    /**
     * The conflict resolver for handling response discrepancies.
     *
     * @var ConflictResolver
     */
    private $conflictResolver;

    /**
     * The OpenAI client for response refinement.
     *
     * @var \OpenAI\Client
     */
    private $openAIClient;

    /**
     * Create a new ResponseIntegrator instance.
     *
     * @param ConflictResolver $resolver The conflict resolver for response discrepancies.
     */
    public function __construct(ConflictResolver $resolver)
    {
        $this->conflictResolver = $resolver;
        $this->openAIClient = OpenAI::client(env('OPENAI_API_KEY'));
    }

    /**
     * Check if a response is relevant based on content and metadata.
     *
     * Filters out responses with errors, empty content, or minimal relevance, ensuring
     * generic applicability without bias toward specific data types.
     *
     * @param array $response The agent response to check.
     * @return bool True if relevant, false otherwise.
     */
    private function isResponseRelevant(array $response): bool
    {
        // Check for errors or missing response
        if (isset($response['error']) || empty($response['response']) || $response['response'] === 'No response text found.') {
            return false;
        }

        $responseText = trim($response['response']);
        $length = strlen($responseText);

        // Exclude very short responses
        if ($length < 50) {
            return false;
        }

        // Check for irrelevance phrases
        $irrelevantPhrases = [
            'cannot provide',
            'no data',
            'not enough information',
            'unable to answer',
            'no relevant',
        ];

        $hasIrrelevantPhrase = false;
        foreach ($irrelevantPhrases as $phrase) {
            if (stripos($responseText, $phrase) !== false) {
                $hasIrrelevantPhrase = true;
                break;
            }
        }

        // Handle "contact support" carefully: only exclude if it dominates
        if (stripos($responseText, 'contact support') !== false) {
            // Estimate if "contact support" is the main content (e.g., >50% of response)
            $supportPhraseLength = strlen('contact support');
            $proportion = $supportPhraseLength / $length;
            if ($proportion > 0.5 || $length < 100) {
                return false;
            }
        }

        // Exclude if irrelevant phrase dominates and response is short
        if ($hasIrrelevantPhrase && $length < 100) {
            return false;
        }

        return true;
    }

    /**
     * Integrate agent responses into a synthesized output.
     *
     * Filters relevant responses, resolves conflicts, combines them, and refines with
     * OpenAI's gpt-4o-mini model, attributing the result to the MultiAgent.
     *
     * @param array $responses The array of agent responses.
     * @param SharedContext $context The shared context for additional data.
     * @return array The refined response and individual responses.
     */
    public function integrate(array $responses, SharedContext $context): array
    {
        $relevantResponses = [];
        $detailedResponses = $responses; // Keep all responses for debugging

        // Filter relevant responses
        foreach ($responses as $response) {
            if ($this->isResponseRelevant($response)) {
                $relevantResponses[] = $response;
            } else {
                Log::info("Filtered response from agent {$response['agent_name']} (ID: {$response['agent_id']}): {$response['response']}");
            }
        }

        // Resolve conflicts
        $resolvedResponses = $this->conflictResolver->resolve($relevantResponses, $context);

        // Log resolved responses for debugging
        Log::debug("Resolved responses:", array_map(function ($response) {
            return [
                'agent_id' => $response['agent_id'],
                'agent_name' => $response['agent_name'],
                'response' => $response['response'],
            ];
        }, $resolvedResponses));

        // Get MultiAgent name
        $multiAgentId = $context->get('multi_agent_id');
        $multiAgent = MultiAgent::find($multiAgentId);
        $multiAgentName = $multiAgent ? $multiAgent->name : 'MultiAgent';

        // Combine responses into a single string
        $combinedResponse = '';
        if (!empty($resolvedResponses)) {
            foreach ($resolvedResponses as $response) {
                $combinedResponse .= "{$response['response']}\n";
            }
        } else {
            $combinedResponse = "No relevant responses were found for your query.";
            Log::warning("No relevant responses for prompt: {$context->get('prompt')}", [
                'multi_agent_id' => $multiAgentId,
                'session_id' => $context->get('session_id'),
            ]);
        }

        // Refine response with OpenAI
        try {
            $refinedResponse = $this->refineWithOpenAI($combinedResponse, $context, $multiAgentName);
        } catch (\Exception $e) {
            Log::error("Error refining response with OpenAI: " . $e->getMessage(), [
                'prompt' => $context->get('prompt'),
                'multi_agent_id' => $multiAgentId,
                'session_id' => $context->get('session_id'),
            ]);
            $refinedResponse = $combinedResponse; // Fallback to combined response
        }

        // Format synthesized response with MultiAgent name
        $synthesizedResponse = "**{$multiAgentName}**: {$refinedResponse}";

        return [
            'synthesized_response' => $synthesizedResponse,
            'individual_responses' => $detailedResponses,
        ];
    }

    /**
     * Refine the combined response using OpenAI's gpt-4o-mini model.
     *
     * Enhances the response to be clear, concise, and intuitive, retaining meaningful
     * details relevant to the query without bias toward specific data types.
     *
     * @param string $combinedResponse The combined agent responses.
     * @param SharedContext $context The shared context for additional data.
     * @param string $multiAgentName The name of the MultiAgent.
     * @return string The refined response.
     */
    private function refineWithOpenAI(string $combinedResponse, SharedContext $context, string $multiAgentName): string
    {
        // Use generic context summary, avoiding domain-specific assumptions
        $contextSummary = "Available data includes responses from multiple agents, which may cover a wide range of topics or formats. The original query is: \"{$context->get('prompt')}\".";
    
        // Include additional context metadata if available (e.g., session or agent details)
        $additionalContext = $context->get('metadata') ? json_encode($context->get('metadata')) : 'No additional metadata provided.';
    
        // Prepare messages for OpenAI
        $messages = [
            [
                'role' => 'system',
                'content' => "You are {$multiAgentName}, a unified assistant. Refine the following response to be clear, concise, and intuitive, directly addressing the query: \"{$context->get('prompt')}\". Prioritize detailed and structured content (e.g., lists, specific recommendations) relevant to the query, using available data: {$contextSummary}. Additional context: {$additionalContext}. Remove redundancies and vague statements (e.g., 'contact support', 'more data needed') unless no specific details exist. Structure the output appropriately (e.g., lists, paragraphs) and maintain a professional tone. If insufficient details are provided, explain briefly.\n\nCombined response:\n{$combinedResponse}",
            ],
            [
                'role' => 'user',
                'content' => $context->get('prompt'),
            ],
        ];
    
        // Call OpenAI API
        try {
            $response = $this->openAIClient->chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => $messages,
                'temperature' => 0.7,
                'max_tokens' => 500,
            ]);
    
            return trim($response->choices[0]->message->content);
        } catch (\Exception $e) {
            Log::error("OpenAI refinement failed: " . $e->getMessage());
            throw $e; // Rethrow to trigger fallback in integrate
        }
    }
}