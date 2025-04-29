<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Services\DataToVector;
use App\Actions\Structures\ManageStructure;
use App\Repositories\QdrantRepository;
use App\DTOs\StructureDTO;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenAI;

class IngestFileJob implements ShouldQueue
{
    use Queueable;

    protected $agent;
    protected $filePath;
    protected $originalFilename;

    public $tries = 3;
    public $timeout = 600;
    public $backoff = 60;

    public function __construct(Agent $agent, string $filePath, string $originalFilename)
    {
        $this->agent = $agent;
        $this->filePath = $filePath;
        $this->originalFilename = $originalFilename;
    }

    public function handle(
        DataToVector $dataToVector,
        QdrantRepository $qdrantRepository,
        ManageStructure $manageStructure,
        OpenAI\Client $openAI
    ): void {
        try {
            Log::debug("Starting file ingestion job", [
                'agent_id' => $this->agent->id,
                'file' => $this->originalFilename,
                'file_path' => $this->filePath,
            ]);

            if (!file_exists($this->filePath) || !is_readable($this->filePath)) {
                throw new \Exception("File not found or not readable: {$this->filePath}");
            }

            $file = new UploadedFile(
                $this->filePath,
                $this->originalFilename,
                null,
                null,
                true
            );

            $count = $dataToVector->ingest($this->agent, $file);

            Log::info("File ingestion job completed", [
                'agent_id' => $this->agent->id,
                'file' => $this->originalFilename,
                'point_count' => $count,
            ]);

            // Fetch a point from Qdrant
            $point = $qdrantRepository->fetchPoint($this->agent->vector_collection);
            if (!$point) {
                throw new \Exception('No data points found in collection after ingestion.');
            }
            $pointPayload = $point['payload'] ?? [];

            // Filter out metadata fields
            $metadataFields = ['file_type', 'original_filename'];
            $filteredPayload = array_diff_key($pointPayload, array_flip($metadataFields));

            // Generate schema from point
            $schema = $this->generateSchemaFromPoint($filteredPayload, $openAI);

            // Ensure agent_id is included
            $schema['specifics']['agent_id'] = [
                'type' => 'number',
                'description' => 'Unique identifier for the agent',
                'example' => (int) $this->agent->id,
                'generator_type' => 'number',
                'manual' => true,
            ];
            if (!in_array('agent_id', $schema['required'])) {
                $schema['required'][] = 'agent_id';
            }

            // Create and save StructureDTO
            $dto = new StructureDTO(
                required: $schema['required'],
                optional: $schema['optional'],
                specifics: $schema['specifics']
            );
            $manageStructure->updateStructure($this->agent, $dto);

            Log::info('Schema generated and saved after ingestion', [
                'agent_id' => $this->agent->id,
                'file' => $this->originalFilename,
                'schema' => $schema,
            ]);

            // Clean up file
            Storage::delete(str_replace(storage_path('app/'), '', $this->filePath));
        } catch (\Throwable $e) {
            Log::error("File ingestion job failed: {$e->getMessage()}", [
                'agent_id' => $this->agent->id,
                'file' => $this->originalFilename,
                'file_path' => $this->filePath,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    protected function generateSchemaFromPoint(array $pointPayload, OpenAI\Client $openAI): array
    {
        try {
            $prompt = "Analyze the following data point from a CSV to define a schema:\n" .
                      json_encode($pointPayload, JSON_PRETTY_PRINT) . "\n" .
                      "Return a JSON object with:\n" .
                      "- 'required': Array of field names that should be required (non-empty, non-null fields).\n" .
                      "- 'optional': Array of field names that are optional (empty, null, or likely missing).\n" .
                      "- 'specifics': Object mapping each field to its properties:\n" .
                      "  - 'type': Inferred type (e.g., 'string', 'number', 'boolean', 'uuid', 'incremental', 'timestamp').\n" .
                      "  - 'description': Brief description based on the value and field name.\n" .
                      "  - 'example': Example value from the data point.\n" .
                      "  - 'generator_type': Type for generation (e.g., 'string', 'number', 'uuid').\n" .
                      "  - 'manual': Boolean, set to true for fields requiring system generation (e.g., 'uuid', 'incremental', 'timestamp').\n" .
                      "Infer types based on patterns:\n" .
                      "- 'id' or numeric sequence → 'incremental', manual: true.\n" .
                      "- 'identifier' or UUID-like (e.g., '550e8400-...') → 'uuid', manual: true.\n" .
                      "- 'created_at', 'updated_at', or ISO 8601 timestamps (e.g., '2025-04-09 11:14:17') → 'timestamp', manual: true.\n" .
                      "- '0' or '1' in fields → 'boolean'.\n" .
                      "- Numeric values without leading zeros → 'number'.\n" .
                      "- All other values → 'string'.\n" .
                      "Do not include 'agent_id', 'file_type', or 'original_filename'. " .
                      "Ensure all fields in 'specifics' are listed in 'required' or 'optional'. " .
                      "Return {} if unresolvable.";

            $response = $openAI->chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => 'Schema definition assistant.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

            $content = trim($response->choices[0]->message->content);
            $content = preg_replace('/^```json\n|\n```$/m', '', $content);
            $content = trim($content);

            Log::info('Schema generated from point', [
                'raw_response' => $response->choices[0]->message->content,
                'cleaned_response' => $content,
            ]);

            $schema = json_decode($content, true);

            if (!is_array($schema) || empty($schema)) {
                Log::warning('Failed to parse schema from OpenAI', [
                    'cleaned_response' => $content,
                ]);
                $specifics = [];
                $required = [];
                $optional = [];
                foreach ($pointPayload as $field => $value) {
                    if (in_array($field, ['agent_id', 'file_type', 'original_filename'])) {
                        continue;
                    }
                    $isNumber = is_numeric($value) && !preg_match('/^0\d+/', $value);
                    $type = match ($field) {
                        'id' => 'incremental',
                        'identifier' => 'uuid',
                        'created_at', 'updated_at' => 'timestamp',
                        default => in_array($value, ['0', '1', 0, 1]) ? 'boolean' : ($isNumber ? 'number' : 'string'),
                    };
                    $specifics[$field] = [
                        'type' => $type,
                        'description' => ucfirst($field) . ' field',
                        'example' => $value ?? '',
                        'generator_type' => $type,
                        'manual' => in_array($field, ['id', 'identifier', 'created_at', 'updated_at']),
                    ];
                    // Add to required if non-empty, else optional
                    if (!empty($value) || in_array($field, ['id', 'identifier', 'created_at', 'updated_at'])) {
                        $required[] = $field;
                    } else {
                        $optional[] = $field;
                    }
                }
                return [
                    'required' => array_unique($required),
                    'optional' => array_unique($optional),
                    'specifics' => $specifics,
                ];
            }

            // Ensure all specifics fields are in required or optional
            $required = $schema['required'] ?? [];
            $optional = $schema['optional'] ?? [];
            foreach ($schema['specifics'] as $field => $spec) {
                if (in_array($field, ['agent_id', 'file_type', 'original_filename'])) {
                    unset($schema['specifics'][$field]);
                    continue;
                }
                if (!in_array($field, $required) && !in_array($field, $optional)) {
                    // Add to required if non-empty or manual, else optional
                    if (!empty($pointPayload[$field]) || ($spec['manual'] ?? false)) {
                        $required[] = $field;
                    } else {
                        $optional[] = $field;
                    }
                }
                // Enforce correct types for specific fields
                if (in_array($field, ['id', 'identifier', 'created_at', 'updated_at']) || in_array($spec['type'], ['uuid', 'incremental', 'timestamp'])) {
                    $schema['specifics'][$field]['type'] = match ($field) {
                        'id' => 'incremental',
                        'identifier' => 'uuid',
                        'created_at', 'updated_at' => 'timestamp',
                        default => $spec['type'],
                    };
                    $schema['specifics'][$field]['generator_type'] = $schema['specifics'][$field]['type'];
                    $schema['specifics'][$field]['manual'] = true;
                }
            }

            // Update schema with corrected required/optional
            $schema['required'] = array_unique($required);
            $schema['optional'] = array_unique($optional);
            // Remove metadata fields from required/optional
            $schema['required'] = array_diff($schema['required'], ['agent_id', 'file_type', 'original_filename']);
            $schema['optional'] = array_diff($schema['optional'], ['agent_id', 'file_type', 'original_filename']);

            return $schema;
        } catch (\Exception $e) {
            Log::error('Failed to generate schema from point', [
                'error' => $e->getMessage(),
            ]);
            $specifics = [];
            $required = [];
            $optional = [];
            foreach ($pointPayload as $field => $value) {
                if (in_array($field, ['agent_id', 'file_type', 'original_filename'])) {
                    continue;
                }
                $isNumber = is_numeric($value) && !preg_match('/^0\d+/', $value);
                $type = match ($field) {
                    'id' => 'incremental',
                    'identifier' => 'uuid',
                    'created_at', 'updated_at' => 'timestamp',
                    default => in_array($value, ['0', '1', 0, 1]) ? 'boolean' : ($isNumber ? 'number' : 'string'),
                };
                $specifics[$field] = [
                    'type' => $type,
                    'description' => ucfirst($field) . ' field',
                    'example' => $value ?? '',
                    'generator_type' => $type,
                    'manual' => in_array($field, ['id', 'identifier', 'created_at', 'updated_at']),
                ];
                // Add to required if non-empty, else optional
                if (!empty($value) || in_array($field, ['id', 'identifier', 'created_at', 'updated_at'])) {
                    $required[] = $field;
                } else {
                    $optional[] = $field;
                }
            }
            return [
                'required' => array_unique($required),
                'optional' => array_unique($optional),
                'specifics' => $specifics,
            ];
        }
    }
}