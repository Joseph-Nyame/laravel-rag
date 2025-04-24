<?php

namespace App\Services;

use App\Models\Agent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenAI;

class VectorService
{
    protected $client;

    public function __construct()
    {
        $this->client = OpenAI::client(env('OPENAI_API_KEY'));
    }

    public function createPoint(Agent $agent, UploadedFile $file, array $items): array
    {
        try {
            Log::debug("Starting VectorService::createPoint", [
                'agent_id' => $agent->id,
                'file' => $file->getClientOriginalName(),
                'item_count' => count($items),
                'memory_usage' => memory_get_usage(true) / 1024 / 1024 . ' MB',
            ]);

            $batchSize = config('services.openai.batch_size', 3);
            $points = [];

            foreach (array_chunk($items, $batchSize) as $index => $batch) {
                Log::debug("Processing batch", [
                    'agent_id' => $agent->id,
                    'file' => $file->getClientOriginalName(),
                    'batch_index' => $index,
                    'batch_size' => count($batch),
                    'memory_usage' => memory_get_usage(true) / 1024 / 1024 . ' MB',
                ]);

                $cacheKey = "vector_service_batch_{$agent->id}_{$index}_" . md5(json_encode($batch));
                $vectors = Cache::remember($cacheKey, now()->addHours(1), function () use ($batch) {
                    $attempts = 3;
                    $delay = 60; // seconds

                    for ($i = 0; $i < $attempts; $i++) {
                        try {
                            $response = $this->client->embeddings()->create([
                                'model' => 'text-embedding-3-small',
                                'input' => array_map(function ($item) {
                                    return is_array($item) ? json_encode($item) : (string) $item;
                                }, $batch),
                            ]);
                            return array_map(fn($emb) => $emb->embedding, $response->embeddings);
                        } catch (\Exception $e) {
                            if (str_contains($e->getMessage(), '429') && $i < $attempts - 1) {
                                Log::warning("OpenAI rate limit hit, retrying", [
                                    'attempt' => $i + 1,
                                    'delay' => $delay,
                                    'error' => $e->getMessage(),
                                ]);
                                sleep($delay);
                                $delay *= 2; // Exponential backoff
                                continue;
                            }
                            throw $e;
                        }
                    }
                });

                foreach ($batch as $i => $item) {
                    $id = (string) Str::uuid();
                    $text = is_array($item) ? json_encode($item) : $item;

                    $points[] = [
                        'id' => $id,
                        'vector' => $vectors[$i],
                        'payload' => array_merge([
                            'agent_id' => $agent->id,
                            'file_type' => $file->getClientOriginalExtension(),
                            'original_filename' => $file->getClientOriginalName(),
                        ], is_array($item) ? $item : ['content' => $text]),
                    ];
                }

                Log::debug("Created batch points", [
                    'agent_id' => $agent->id,
                    'file' => $file->getClientOriginalName(),
                    'batch_index' => $index,
                    'point_count' => count($batch),
                    'memory_usage' => memory_get_usage(true) / 1024 / 1024 . ' MB',
                ]);

                Cache::forget($cacheKey);
                unset($vectors); // Free memory
            }

            Log::debug("Completed VectorService::createPoint", [
                'agent_id' => $agent->id,
                'file' => $file->getClientOriginalName(),
                'total_point_count' => count($points),
                'memory_usage' => memory_get_usage(true) / 1024 / 1024 . ' MB',
            ]);

            return $points;
        } catch (\Exception $e) {
            Log::error("Failed to create points: {$e->getMessage()}", [
                'agent_id' => $agent->id,
                'file' => $file->getClientOriginalName(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}