<?php

namespace App\Services;

use App\Jobs\ProcessCommandJob;
use App\Models\Agent;
use App\Models\Structure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CommandHandler
{
    protected $intentClass;
    protected $entityExtraction;
    protected $specificsHandler;

    public function __construct(
        IntentClass $intentClass,
        EntityExtractionClass $entityExtraction,
        SchemaSpecificsHandler $specificsHandler
    ) {
        $this->intentClass = $intentClass;
        $this->entityExtraction = $entityExtraction;
        $this->specificsHandler = $specificsHandler;
    }

    public function handle(string $command, Agent $agent): array
    {
        try {
            $intent = $this->intentClass->identify($command, $agent->name . ' Agent');
            // Clean intent to remove verbose prefix
            $intent = preg_replace('/^The intent of the command is \'(.+)\'\.$/', '$1', $intent);
            $data = $this->entityExtraction->extract($command, $agent);

            // Get structure schema
            $structure = Structure::where('agent_id', $agent->id)->first();
            if (!$structure) {
                throw new \Exception('No structure defined for agent');
            }

            // Auto-generate fields for create_entity based on specifics
            if (strpos($intent, 'create_') === 0) {
                $specifics = $structure->schema['specifics'] ?? [];
                $requiredFields = $structure->schema['required'] ?? [];
                $collectionName = $agent->vector_collection;
                $data = $this->specificsHandler->generateFields(
                    $specifics,
                    $data,
                    $agent->id,
                    $requiredFields,
                    $collectionName
                );
            }

            // Validate data against structure schema
            $schema = $structure->schema;
            $specifics = $schema['specifics'] ?? [];
            foreach ($schema['required'] ?? [] as $field) {
                // Skip validation for manual fields, as they'll be generated
                if (isset($specifics[$field]['manual']) && $specifics[$field]['manual']) {
                    continue;
                }
                if (!isset($data[$field]) || empty($data[$field])) {
                    throw new \Exception("Missing required field: $field");
                }
            }

            foreach ($data as $key => $value) {
                if (!in_array($key, $schema['required'] ?? []) && !in_array($key, $schema['optional'] ?? [])) {
                    throw new \Exception("Invalid field: $key");
                }
            }

            // Dispatch job
            $jobId = (string) Str::uuid();
            ProcessCommandJob::dispatch($intent, $data, $agent);

            // Generate feedback
            $entity = explode('_', $intent)[1] ?? 'entity';
            $action = explode('_', $intent)[0] ?? 'action';
            $name = $data['name'] ?? $data['id'] ?? $entity;

            if ($action === 'create') {
                $message = ucfirst($entity) . " $name added.";
            } elseif ($action === 'read') {
                $message = ucfirst($entity) . " listed.";
            } elseif ($action === 'update') {
                $message = ucfirst($entity) . " $name updated.";
            } elseif ($action === 'delete') {
                $message = ucfirst($entity) . " deleted.";
            } else {
                $message = ucfirst($entity) . " processed.";
            }

            Log::info('Command handled successfully', [
                'command' => $command,
                'agent_id' => $agent->id,
                'intent' => $intent,
                'data' => $data,
                'job_id' => $jobId,
            ]);

            return [
                'message' => $message,
                'job_id' => $jobId,
            ];
        } catch (\Exception $e) {
            Log::error('Command handling failed', [
                'command' => $command,
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}