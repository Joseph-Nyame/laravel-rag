<?php

namespace App\Services\MultiAgent\Integration;

use App\Services\MultiAgent\Context\SharedContext;
use Illuminate\Support\Facades\Log;

/**
 * Integrates and synthesizes agent responses in the multi-agent system.
 *
 * Filters irrelevant responses and combines relevant ones into a unified, readable
 * output, addressing relevance filtering and synthesis limitations. Works with test
 * agents of any type, using shared context for additional data.
 */
class ResponseIntegrator
{
    /**
     * The conflict resolver for handling response discrepancies.
     *
     * @var ConflictResolver
     */
    protected $conflictResolver;

    /**
     * Create a new ResponseIntegrator instance.
     *
     * @param ConflictResolver $conflictResolver The conflict resolver for response discrepancies.
     */
    public function __construct(ConflictResolver $conflictResolver)
    {
        $this->conflictResolver = $conflictResolver;
    }

    /**
     * Check if a response is relevant based on content and metadata.
     *
     * Filters out responses with errors, empty content, or phrases indicating
     * irrelevance (e.g., "cannot provide").
     *
     * @param array $response The agent response to check.
     * @return bool True if relevant, false otherwise.
     */
    protected function isResponseRelevant(array $response): bool
    {
        // Check for errors or missing response
        if (isset($response['error']) || empty($response['response']) || $response['response'] === 'No response text found.') {
            return false;
        }

        // Check for irrelevance phrases
        $irrelevantPhrases = [
            'cannot provide',
            'no data',
            'not enough information',
            'contact support',
            'unable to answer',
            'no relevant',
        ];

        foreach ($irrelevantPhrases as $phrase) {
            if (stripos($response['response'], $phrase) !== false) {
                return false;
            }
        }

        // Ensure response has meaningful content
        return strlen(trim($response['response'])) > 20;
    }

    /**
     * Integrate agent responses into a synthesized output.
     *
     * Filters relevant responses, resolves conflicts, and formats a unified response.
     *
     * @param array $responses The array of agent responses.
     * @param SharedContext $context The shared context for additional data.
     * @return array The synthesized response and individual responses.
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
                Log::info("Excluded irrelevant response from agent {$response['agent_name']} (ID: {$response['agent_id']}): {$response['response']}");
            }
        }

        // Resolve conflicts
        $resolvedResponses = $this->conflictResolver->resolve($relevantResponses, $context);

        // Synthesize response
        $synthesizedResponse = '';
        if (!empty($resolvedResponses)) {
            $synthesizedResponse .= "Summary of relevant responses:\n\n";
            foreach ($resolvedResponses as $response) {
                $synthesizedResponse .= "- **{$response['agent_name']}**: {$response['response']}\n\n";
            }
        } else {
            $synthesizedResponse = "No relevant responses were found for your query.";
            Log::warning("No relevant responses for prompt: {$context->get('prompt')}", [
                'multi_agent_id' => $context->get('multi_agent_id'),
                'session_id' => $context->get('session_id'),
            ]);
        }

        return [
            'synthesized_response' => $synthesizedResponse,
            'individual_responses' => $detailedResponses,
        ];
    }
}