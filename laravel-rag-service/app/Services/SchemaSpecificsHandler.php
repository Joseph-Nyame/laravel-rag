<?php

namespace App\Services;

use OpenAI;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Repositories\QdrantRepository;

class SchemaSpecificsHandler
{
    protected $client;
    protected $supportedTypes = ['uuid', 'incremental', 'timestamp', 'number'];
    protected $generators;
    protected $metadataFields = ['file_type', 'original_filename'];

    public function __construct(
        public QdrantRepository $qdrantRepository
    ) {
        $this->client = OpenAI::client(env('OPENAI_API_KEY'));
        $this->generators = [
            'hex_code' => fn() => sprintf('#%06X', mt_rand(0, 0xFFFFFF)),
            'random_string' => fn() => Str::random(8),
            'email' => fn() => Str::random(8) . '@example.com',
            'uuid' => fn() => (string) Str::uuid(),
            'timestamp' => fn() => now()->toIso8601String(),
            'string' => fn() => Str::random(8),
            'number' => fn() => mt_rand(1, 1000),
            'boolean' => fn() => mt_rand(0, 1),
        ];
    }

    public function generateFields(array $specifics, array $existingData, string $agentId, array $requiredFields, string $collectionName): array
    {
        $data = $existingData;

        foreach ($specifics as $field => $spec) {
            if (isset($data[$field]) || in_array($field, $this->metadataFields)) {
                continue; // Skip user-provided fields and metadata
            }

            $type = $spec['type'] ?? 'string';
            $generatorType = $spec['generator_type'] ?? $type;
            $isManual = $spec['manual'] ?? false;

            if ($isManual && in_array($type, $this->supportedTypes)) {
                switch ($type) {
                    case 'uuid':
                        $data[$field] = (string) Str::uuid();
                        break;
                    case 'incremental':
                        $counter = DB::table('schema_counters')
                            ->where('agent_id', $agentId)
                            ->where('field', $field)
                            ->first();
                        $nextId = ($counter ? $counter->value : 0) + 1;
                        DB::table('schema_counters')->updateOrInsert(
                            ['agent_id' => $agentId, 'field' => $field],
                            ['value' => $nextId, 'updated_at' => now()]
                        );
                        $data[$field] = $nextId;
                        break;
                    case 'timestamp':
                        $data[$field] = now()->toIso8601String();
                        break;
                    case 'number':
                        if ($field === 'agent_id') {
                            $data[$field] = (int) $agentId;
                        } else {
                            $data[$field] = mt_rand(1, 1000);
                        }
                        break;
                }
            } else {
                switch ($type) {
                    case 'timestamp':
                        $data[$field] = now()->toIso8601String();
                        break;
                    case 'enum':
                        $options = explode(',', $spec['enum_values'] ?? '');
                        $data[$field] = !empty($options) ? $options[array_rand($options)] : Str::random(8);
                        break;
                    default:
                        if (isset($this->generators[$generatorType])) {
                            $data[$field] = ($this->generators[$generatorType])();
                        } else {
                            $value = $this->generateCustomFieldValue($field, $type, $agentId, $collectionName, in_array($field, $requiredFields));
                            if ($value !== null) {
                                $data[$field] = $value;
                            } elseif (in_array($field, $requiredFields)) {
                                throw new \Exception("Unresolvable specific type '$type' for required field '$field'.");
                            }
                        }
                        break;
                }
            }
        }

        return $data;
    }

    protected function generateCustomFieldValue(string $field, string $type, string $agentId, string $collectionName, bool $isRequired)
    {
        $cacheKey = "specific_type_definition_{$agentId}_{$field}_{$type}";
        $definition = Cache::remember($cacheKey, now()->addDays(7), function () use ($field, $type, $collectionName) {
            $point = $this->qdrantRepository->fetchPoint($collectionName);
            if (!$point) {
                Log::warning('No points in collection', ['collection' => $collectionName]);
                return [];
            }
            $pointPayload = $point['payload'] ?? [];

            try {
                $prompt = "Define type for field '$field' with type '$type'. " .
                          "Sample data: " . json_encode($pointPayload) . ". " .
                          "Return JSON with: " .
                          "- 'description': Brief description. " .
                          "- 'example': Example value. " .
                          "- 'generator_type': Type (e.g., 'string', 'number'). " .
                          "Use sample to validate type. Return {} if unresolvable.";
                $response = $this->client->chat()->create([
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'system', 'content' => 'Data type definition assistant.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

                $content = trim($response->choices[0]->message->content);
                $content = preg_replace('/^```json\n|\n```$/m', '', $content);
                $content = trim($content);

                Log::info("Custom field value definition for '$field'", [
                    'type' => $type,
                    'raw_response' => $response->choices[0]->message->content,
                    'cleaned_response' => $content,
                ]);

                return json_decode($content, true) ?? [];
            } catch (\Exception $e) {
                Log::error('Failed to define specific type', [
                    'field' => $field,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
                return [];
            }
        });

        if (empty($definition)) {
            Log::warning('Unresolvable specific type', [
                'field' => $field,
                'type' => $type,
                'agent_id' => $agentId,
            ]);
            return null;
        }

        $generatorType = $definition['generator_type'] ?? null;
        if ($generatorType && isset($this->generators[$generatorType])) {
            return ($this->generators[$generatorType])();
        }

        Log::warning('No generator for type', [
            'field' => $field,
            'type' => $type,
            'generator_type' => $generatorType,
        ]);
        return $definition['example'] ?? null;
    }
}