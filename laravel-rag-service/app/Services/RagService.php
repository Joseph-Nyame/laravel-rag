<?php

namespace App\Services;

use OpenAI;
use App\Models\Agent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class RagService
{
    protected string $vectorDbUrl;
    private array $messages = [];
    protected $client;
    protected PromptManager $promptManager;

    public function __construct(PromptManager $promptManager)
    {
        $this->vectorDbUrl = config('services.qdrant.host');
        $this->client = OpenAI::client(env('OPENAI_API_KEY'));
        $this->promptManager = $promptManager;
    }

    public function chat(Agent $agent, string $query, array $conversationHistory = []): array
    {
        $scenario = $this->promptManager->detectScenario($query);
        $isComplete = in_array($scenario, ['count', 'list']);
        $context = $this->queryData($agent, $query, $scenario);

        Log::info('Retrieved context', ['scenario' => $scenario, 'context' => $context]);

        $prompt = $this->promptManager->getPrompt($query, $context, $isComplete);

        $messages = $this->buildMessages($query, $prompt, $conversationHistory);

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

    private function buildMessages(string $query, string $prompt, array $history): array
    {
        $this->messages = [];
        $this->messages[] = [
            'role' => 'system',
            'content' => $prompt,
        ];
        foreach ($history as $message) {
            $this->messages[] = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }
        $this->messages[] = [
            'role' => 'user',
            'content' => $query,
        ];

        Log::info('Messages sent to OpenAI', ['messages' => $this->messages]);

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

    private function queryData(Agent $agent, string $query, string $scenario): array
    {
        if (in_array($scenario, ['count', 'list'])) {
            return $this->scrollQuerySearch($agent, $query);
        }
        return $this->vectorQuerySearch($agent, $query);
    }

    private function vectorQuerySearch(Agent $agent, string $query): array
    {
        $queryVector = $this->getEmbeddings($query);
        Log::info('Query vector', ['vector' => $queryVector]);

        $headers = ['Content-Type' => 'application/json'];
        if ($apiKey = config('services.qdrant.api_key')) {
            $headers['api-key'] = $apiKey;
        }

        $response = Http::withHeaders($headers)->post(
            "{$this->vectorDbUrl}/collections/{$agent->vector_collection}/points/search",
            [
                'vector' => $queryVector,
                'limit' => 100,
                'with_payload' => true,
            ]
        );

        if ($response->failed()) {
            Log::error('Vector search failed', ['error' => $response->body()]);
            throw new \Exception('Vector search failed: ' . $response->body());
        }

        $results = $response->json()['result'] ?? [];

        return collect($results)->map(function ($result) {
            return $result['payload'];
        })->toArray();
    }

    private function scrollQuerySearch(Agent $agent, string $query): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($apiKey = config('services.qdrant.api_key')) {
            $headers['api-key'] = $apiKey;
        }

        $filter = null;
        if (preg_match('/how many\s+(males|females)/i', $query, $matches)) {
            $gender = strtolower($matches[1]) === 'males' ? 'male' : 'female';
            $filter = [
                'must' => [
                    [
                        'key' => 'gender',
                        'match' => ['value' => $gender],
                    ],
                ],
            ];
        } elseif (preg_match('/\b(list|show all)\s+.*(admins|users)/i', $query, $matches)) {
            $role = strtolower($matches[2]) === 'admins' ? 'admin' : 'user';
            $filter = [
                'must' => [
                    [
                        'key' => 'role',
                        'match' => ['value' => $role],
                    ],
                ],
            ];
        } elseif (preg_match('/(how many|count|number of)\s+(males|females)\s+.*(admins|users)/i', $query, $matches)) {
            $gender = strtolower($matches[2]) === 'males' ? 'male' : 'female';
            $role = strtolower($matches[3]) === 'admins' ? 'admin' : 'user';
            $filter = [
                'must' => [
                    ['key' => 'gender', 'match' => ['value' => $gender]],
                    ['key' => 'role', 'match' => ['value' => $role]],
                ],
            ];
        }

        $response = Http::withHeaders($headers)->post(
            "{$this->vectorDbUrl}/collections/{$agent->vector_collection}/points/scroll",
            [
                'filter' => $filter,
                'limit' => 300,
                'with_payload' => true,
            ]
        );

        if ($response->failed()) {
            Log::error('Scroll search failed', ['error' => $response->body()]);
            throw new \Exception('Scroll search failed: ' . $response->body());
        }

        $results = $response->json()['result']['points'] ?? [];

        return collect($results)->map(function ($result) {
            return $result['payload'];
        })->toArray();
    }
}