<?php

namespace App\Services\MultiAgent\Integration;

use App\Services\MultiAgent\Context\SharedContext;
use Illuminate\Support\Facades\Log;

/**
 * Resolves conflicts between agent responses in the multi-agent system.
 *
 * Handles discrepancies (e.g., conflicting totals) to ensure consistent data for
 * synthesis, enhancing synthesis quality. Works with test agents of any type,
 * using shared context for additional data.
 */
class ConflictResolver
{
    /**
     * Resolve conflicts between agent responses.
     *
     * Uses confidence scores or response completeness to prioritize responses,
     * resolving discrepancies (e.g., differing totals).
     *
     * @param array $responses The array of agent responses.
     * @param SharedContext $context The shared context for additional data.
     * @return array The resolved responses.
     */
    public function resolve(array $responses, SharedContext $context): array
    {
        if (empty($responses)) {
            return [];
        }

        $resolvedResponses = [];
        $numericValues = []; // Track numeric data (e.g., totals) for conflict detection

        foreach ($responses as $response) {
            $agentId = $response['agent_id'];
            $confidence = $context->get("agent_{$agentId}_data.raw_details.confidence", 0.5); // Default confidence

            // Extract numeric values (e.g., totals) for conflict detection
            if (preg_match('/Total amount[^:]*: \$([\d,.]+)/i', $response['response'], $matches)) {
                $numericValues[$agentId] = [
                    'value' => floatval(str_replace(',', '', $matches[1])),
                    'confidence' => $confidence,
                    'response' => $response,
                ];
            } else {
                // Non-numeric responses are included as-is
                $resolvedResponses[] = $response;
            }
        }

        // Resolve numeric conflicts (e.g., differing totals)
        if (!empty($numericValues)) {
            $highestConfidence = max(array_column($numericValues, 'confidence'));
            $bestResponse = null;

            foreach ($numericValues as $data) {
                if ($data['confidence'] >= $highestConfidence) {
                    $bestResponse = $data['response'];
                    break;
                }
            }

            if ($bestResponse) {
                $resolvedResponses[] = $bestResponse;
            }

            // Log conflicts if multiple numeric values exist
            if (count($numericValues) > 1) {
                Log::info("Resolved numeric conflict", [
                    'values' => array_map(fn($v) => $v['value'], $numericValues),
                    'chosen' => $bestResponse['agent_name'] ?? 'unknown',
                    'prompt' => $context->get('prompt'),
                ]);
            }
        }

        return $resolvedResponses;
    }
}