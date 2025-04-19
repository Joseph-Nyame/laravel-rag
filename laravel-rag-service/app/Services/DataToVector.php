<?php

namespace App\Services;

use App\Models\Agent;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use OpenAI;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class DataToVector
{
    protected string $vectorDbUrl;

    protected $client;

    public function __construct()
    {
        $this->vectorDbUrl = config('services.qdrant.host');
        $this->client = OpenAI::client(env('OPENAI_API_KEY'));
    }

    public function ingest(Agent $agent, UploadedFile $file): int
    {
        try {
            $dataItems = $this->parseFile($file);
            if (empty($dataItems)) {
                Log::info('No data extracted from file', ['file' => $file->getClientOriginalName()]);
                throw new \Exception('No data extracted from file.');
            }

            $points = [];
            foreach ($dataItems as $index => $item) {
                $id = (string) Str::uuid();
                $text = is_array($item) ? json_encode($item) : $item;

                $response = $this->client->embeddings()->create([
                    'model' => 'text-embedding-3-small',
                    'input' => $text,
                ]);

                $points[] = [
                    'id' => $id,
                    'vector' => $response->embeddings[0]->embedding,
                    'payload' => array_merge([
                        'agent_id' => $agent->id,
                        'file_type' => $file->getClientOriginalExtension(),
                        'original_filename' => $file->getClientOriginalName(),
                    ], is_array($item) ? $item : ['content' => $text]),
                ];
            }

            Log::debug('Preparing to upsert points', [
                'collection' => $agent->vector_collection,
                'points_count' => count($points),
                'file' => $file->getClientOriginalName(),
            ]);

            // Check if collection exists
            $collectionResponse = Http::get("{$this->vectorDbUrl}/collections/{$agent->vector_collection}");
            if ($collectionResponse->failed() || $collectionResponse->json('result.status') !== 'green') {
                $error = $collectionResponse->json('status.error', 'Unknown error');
                Log::error('Qdrant collection not found', [
                    'collection' => $agent->vector_collection,
                    'error' => $error,
                    'response' => $collectionResponse->body(),
                ]);
                throw new \Exception("Collection {$agent->vector_collection} does not exist: {$error}");
            }

            // Batch upsert to Qdrant
            $response = Http::put("{$this->vectorDbUrl}/collections/{$agent->vector_collection}/points?wait=true", [
                'points' => $points,
            ]);

            Log::info('Qdrant upsert response', ['response' => $response->body()]);

            if ($response->failed() || $response->json('result.status') !== 'completed') {
                $error = $response->json('status.error', 'Unknown error');
                Log::error('Failed to upsert points', [
                    'collection' => $agent->vector_collection,
                    'error' => $error,
                    'response' => $response->body(),
                ]);
                throw new \Exception("Failed to upsert points: {$error}");
            }

            Log::info('Upsert successful', [
                'collection' => $agent->vector_collection,
                'points_count' => count($points),
                'file' => $file->getClientOriginalName(),
            ]);

            return count($dataItems);
        } catch (\Exception $e) {
            Log::error("DataToVector ingest failed for agent {$agent->id}", [
                'error' => $e->getMessage(),
                'file' => $file->getClientOriginalName(),
            ]);
            throw $e;
        }
    }

    protected function parseFile(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $data = [];

        try {
            if ($extension === 'csv') {
                $handle = fopen($file->getPathname(), 'r');
                $headers = fgetcsv($handle);
                while ($row = fgetcsv($handle)) {
                    $data[] = array_combine($headers, $row);
                }
                fclose($handle);
            } elseif ($extension === 'txt') {
                $content = file_get_contents($file->getPathname());
                $data = array_filter(array_map('trim', explode("\n", $content)));
            } elseif ($extension === 'sql') {
                $content = file_get_contents($file->getPathname());
                $data = array_filter(array_map('trim', explode(';', $content)));
            } elseif ($extension === 'json') {
                $content = json_decode(file_get_contents($file->getPathname()), true);
                $data = is_array($content) ? $content : [$content];
            } else {
                throw new \Exception('Unsupported file type: ' . $extension);
            }

            return $data;
        } catch (\Exception $e) {
            Log::error("File parsing failed: {$e->getMessage()}", [
                'file' => $file->getClientOriginalName(),
            ]);
            throw $e;
        }
    }
}