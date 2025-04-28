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
                $prompt = "Identify the intent of the command: '$command' for a $agentContext. Return the intent as a string. " .
                          "If it's a CRUD action, use 'action_entity' format (e.g., 'create_product', 'read_product', 'update_order', 'delete_product'), " .
                          "where 'action' is 'create', 'read', 'update', or 'delete', and 'entity' is the target (e.g., 'product', 'order'). " .
                          "Use 'read_entity' for specific retrieval requests (e.g., 'Show product with id 1' → 'read_product'). " .
                          "If it's a general conversational or RAG query (e.g., 'What are the products?', 'Tell me about orders'), return 'rag_query'.";
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