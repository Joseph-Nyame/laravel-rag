<?php

namespace App\Services;

use App\Models\Agent;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use OpenAI;
use Illuminate\Support\Facades\Log;

class VectorService
{
    protected $client;

    public function __construct()
    {
        $this->client = OpenAI::client(env('OPENAI_API_KEY'));
    }

    public function createPoint(Agent $agent, UploadedFile $file, $item): array
    {
        try {
            $id = (string) Str::uuid();
            $text = is_array($item) ? json_encode($item) : $item;

            $response = $this->client->embeddings()->create([
                'model' => 'text-embedding-3-small',
                'input' => $text,
            ]);

            return [
                'id' => $id,
                'vector' => $response->embeddings[0]->embedding,
                'payload' => array_merge([
                    'agent_id' => $agent->id,
                    'file_type' => $file->getClientOriginalExtension(),
                    'original_filename' => $file->getClientOriginalName(),
                ], is_array($item) ? $item : ['content' => $text]),
            ];
        } catch (\Exception $e) {
            Log::error("Failed to create point: {$e->getMessage()}", [
                'agent_id' => $agent->id,
                'file' => $file->getClientOriginalName(),
            ]);
            throw $e;
        }
    }
}