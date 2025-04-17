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
                throw new \Exception('No data extracted from file.');
            }

            $points = [];
            foreach ($dataItems as $index => $item) {
                $id = "item_{$agent->id}_{$index}_" . Str::random(8);
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

            // Batch upsert to Qdrant
            $response = Http::put("{$this->vectorDbUrl}/collections/{$agent->vector_collection}/points?wait=true", [
                'points' => $points,
            ]);

            if ($response->failed()) {
                throw new \Exception('Failed to add items: ' . $response->body());
            }

            return count($dataItems);
        } catch (\Exception $e) {
            Log::error("DataToVector ingest failed for agent {$agent->id}: {$e->getMessage()}");
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
            }
            elseif ($extension === 'json') {
                $content = json_decode(file_get_contents($file->getPathname()), true);
                $data = is_array($content) ? $content : [$content];
            } else {
                throw new \Exception('Unsupported file type: ' . $extension);
            }

            return $data;
        } catch (\Exception $e) {
            Log::error("File parsing failed: {$e->getMessage()}");
            throw $e;
        }
    }
}