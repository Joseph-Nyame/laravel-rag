<?php

namespace App\Services;

use App\Models\Agent;
use Illuminate\Support\Facades\Http;
use OpenAI\Laravel\Facades\OpenAI;

class RAGService
{
    protected string $vectorDbUrl;

    private array $messages = [];

    protected $client;

    private string $systemPrompt = <<<'EOT'
You are an AI assistant specialized in answering questions based on user-uploaded data. Use the following context to provide accurate responses.
Context: {context}
Answer the question based on the context provided. If the context doesn't contain relevant information, say so clearly.
EOT;

    public function __construct()
    {
        $this->vectorDbUrl = config('services.qdrant.host');
        $this->client = OpenAI::client('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');


    }

    public function chat(Agent $agent, string $query, array $conversationHistory = []): array
    {
        // Retrieve relevant context from Qdrant
        $context = $this->vectorQuerySearch($agent, $query);

        // Generate response using OpenAI
        $messages = $this->buildMessages($query, $context, $conversationHistory);

        $response = $this->client->chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 500,
        ]);

        return [
            'response' => $response->choices[0]->message->content,
            'context' => $context,
        ];
    }

    private function buildMessages(string $query, array $context, array $history): array
    {
        // Reset messages
        $this->messages = [];

        // System prompt with context
        $this->messages[] = [
            'role' => 'system',
            'content' => str_replace('{context}', json_encode($context), $this->systemPrompt),
        ];

        // Conversation history
        foreach ($history as $message) {
            $this->messages[] = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }

        // Current query
        $this->messages[] = [
            'role' => 'user',
            'content' => $query,
        ];

        return $this->messages;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    private function getEmbeddings(string $query): array
    {
        $response = $this->client->embeddings()->create([
            'model' => 'text-embedding-3-small',
            'input' => $query,
        ]);

        return $response->embeddings[0]->embedding;
    }

    private function vectorQuerySearch(Agent $agent, string $query): array
    {
        $queryVector = $this->getEmbeddings($query);

        $response = Http::post("{$this->vectorDbUrl}/collections/{$agent->vector_collection}/points/search", [
            'vector' => $queryVector,
            'limit' => 5,
            'with_payload' => true,
        ]);

        if ($response->failed()) {
            throw new \Exception('Vector search failed: ' . $response->body());
        }

        $results = $response->json()['result'];

        return collect($results)->map(function ($result) {
            return $result['payload'];
        })->toArray();
    }
}