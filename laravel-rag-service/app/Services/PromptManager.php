<?php

namespace App\Services;

use App\Models\Prompt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenAI;

class PromptManager
{
    protected $client;

    // Template for generating new prompts
    private array $promptTemplates = [
        'count' => 'You are an AI assistant specialized in answering count-based questions using user-uploaded data. The provided context contains either all relevant data (for precise counts) or a subset of the dataset (for approximate counts). Context: {context} Count all relevant items in the context that match the query (e.g., all {entity} for "how many {entity}"). If the context is marked as complete (is_complete: true), provide the exact count. If incomplete (is_complete: false), provide an approximate count (e.g., "over X {entity}") and note that the dataset may contain more matches. If the context lacks relevant information, state so clearly.',
        'list' => 'You are an AI assistant specialized in listing items from user-uploaded data. The provided context contains either all relevant data or a subset of the dataset. Context: {context} List all items in the context that match the query (e.g., all {entity} for "list all {entity}"). If the context is marked as complete (is_complete: true), list all matches. If incomplete (is_complete: false), list available matches and note that the dataset may contain more. If the context lacks relevant information, state so clearly.',
        'general' => 'You are an AI assistant specialized in answering questions based on user-uploaded data. The provided context is a subset of the full dataset, retrieved via semantic search, and may not include all relevant information. Context: {context} Answer the query accurately based on the context. If the context is insufficient or the query requires specific processing, suggest contacting support to add support for this query type.',
    ];

    private string $cachePrefix = 'prompt:';

    public function __construct()
    {
        $this->client = OpenAI::client(env('OPENAI_API_KEY'));
    }

    public function getPrompt(string $query, array $context, bool $isComplete = false): string
    {
        $scenario = $this->detectScenario($query);
        $cacheKey = $this->getCacheKey($scenario);

        // Try cache first
        $prompt = Cache::get($cacheKey);

        if (!$prompt) {
            // Check database
            $promptModel = Prompt::where('scenario', $scenario)->first();

            if ($promptModel) {
                $prompt = $promptModel->prompt;
            } else {
                // Generate new prompt
                $prompt = $this->generatePrompt($scenario, $query);
                Prompt::create([
                    'scenario' => $scenario,
                    'prompt' => $prompt,
                    'is_auto_generated' => true,
                ]);
                Log::info('New scenario and prompt created', [
                    'scenario' => $scenario,
                    'query' => $query,
                ]);
            }

            Cache::put($cacheKey, $prompt, now()->addDay());
        }

        return str_replace(
            '{context}',
            json_encode(['data' => $context, 'is_complete' => $isComplete]),
            $prompt
        );
    }

    public function updatePrompt(string $scenario, string $newPrompt): void
    {
        $cacheKey = $this->getCacheKey($scenario);
        Prompt::updateOrCreate(
            ['scenario' => $scenario],
            ['prompt' => $newPrompt, 'is_auto_generated' => false]
        );
        Cache::put($cacheKey, $newPrompt, now()->addDay());
        Log::info('Prompt updated', ['scenario' => $scenario]);
    }

    public function detectScenario(string $query): string
    {
        $query = Str::lower($query);

        // Predefined scenarios
        if (preg_match('/\b(how many|count|number of)\b/i', $query)) {
            return 'count';
        }
        if (preg_match('/\b(list|show all)\b/i', $query)) {
            return 'list';
        }

        // Dynamic scenario detection using embeddings
        $existingPrompts = Prompt::pluck('scenario')->toArray();
        if (empty($existingPrompts)) {
            return 'general';
        }

        $queryEmbedding = $this->getEmbeddings($query);
        $similarityScores = [];

        foreach ($existingPrompts as $scenario) {
            $scenarioEmbedding = $this->getEmbeddings($scenario);
            $similarity = $this->cosineSimilarity($queryEmbedding, $scenarioEmbedding);
            $similarityScores[$scenario] = $similarity;
        }

        // Find the best match
        arsort($similarityScores);
        $bestScenario = key($similarityScores);
        $bestScore = reset($similarityScores);

        // Threshold for new scenario (adjust as needed)
        if ($bestScore < 0.8) {
            $newScenario = $this->generateScenarioName($query);
            Log::info('New scenario detected', ['query' => $query, 'scenario' => $newScenario]);
            return $newScenario;
        }

        return $bestScenario;
    }

    private function generatePrompt(string $scenario, string $query): string
    {
        // Determine template based on query intent
        $template = $this->promptTemplates['general'];
        $entity = 'items';

        if (preg_match('/\b(how many|count|number of)\b.*(male|female|admin|user)/i', $query, $matches)) {
            $template = $this->promptTemplates['count'];
            $entity = strtolower($matches[2]) . 's';
        } elseif (preg_match('/\b(list|show all)\b.*(admin|user)/i', $query, $matches)) {
            $template = $this->promptTemplates['list'];
            $entity = strtolower($matches[2]) . 's';
        }

        return str_replace('{entity}', $entity, $template);
    }

    private function generateScenarioName(string $query): string
    {
        // Simplified: Use query keywords
        $words = explode(' ', Str::lower($query));
        $keywords = array_filter($words, fn($word) => strlen($word) > 3);
        return Str::slug(implode('-', array_slice($keywords, 0, 3)));
    }

    private function getEmbeddings(string $text): array
    {
        $response = $this->client->embeddings()->create([
            'model' => 'text-embedding-3-small',
            'input' => $text,
        ]);
        return $response->embeddings[0]->embedding;
    }

    private function cosineSimilarity(array $vec1, array $vec2): float
    {
        $dotProduct = 0.0;
        $norm1 = 0.0;
        $norm2 = 0.0;

        foreach ($vec1 as $i => $value) {
            $dotProduct += $value * $vec2[$i];
            $norm1 += $value * $value;
            $norm2 += $vec2[$i] * $vec2[$i];
        }

        $norm1 = sqrt($norm1);
        $norm2 = sqrt($norm2);

        if ($norm1 === 0.0 || $norm2 === 0.0) {
            return 0.0;
        }

        return $dotProduct / ($norm1 * $norm2);
    }

    private function getCacheKey(string $scenario): string
    {
        return $this->cachePrefix . $scenario;
    }
}