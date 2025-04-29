<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use OpenAI;

class IntentClass
{
    protected $client;

    public function __construct()
    {
        $this->client = OpenAI::client(env('OPENAI_API_KEY'));
    }

    public function identify(string $command, string $agentContext): string
    {
        try {
            $cacheKey = 'intent_identification_' . md5($command . $agentContext);
            return Cache::remember($cacheKey, now()->addHours(1), function () use ($command, $agentContext) {
                $prompt = "Identify the intent of the command: '$command' for a $agentContext. " .
                          "Return only the intent as a string in 'action_entity' format for CRUD actions " .
                          "(e.g., 'create_member', 'read_member', 'update_order', 'delete_product'), " .
                          "where 'action' is 'create', 'read', 'update', or 'delete', and 'entity' is the target (e.g., 'member', 'product'). " .
                          "Use 'read_entity' for specific retrieval requests (e.g., 'Show member with id 1' → 'read_member'). " .
                          "For general conversational or RAG queries (e.g., 'What are the members?'), return 'rag_query'. " .
                          "Do not include prefixes like 'The intent is' or punctuation.";

                $response = $this->client->chat()->create([
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are an NLP assistant identifying command intents.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

                $intent = trim($response->choices[0]->message->content);
                Log::info('Intent identification successful', [
                    'command' => $command,
                    'agent_context' => $agentContext,
                    'intent' => $intent,
                ]);
                return $intent;
            });
        } catch (\Exception $e) {
            Log::error('Intent identification failed', [
                'command' => $command,
                'agent_context' => $agentContext,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}