<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Repositories\QdrantRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessCommandJob implements ShouldQueue
{
    use Queueable;

    protected $intent;
    protected $data;
    protected $agent;

    public $tries = 3;
    public $timeout = 120;

    public function __construct(string $intent, array $data, Agent $agent)
    {
        $this->intent = $intent;
        $this->data = $data;
        $this->agent = $agent;
    }

    public function handle(QdrantRepository $qdrantRepository): void
    {
        try {
            $collectionName = $this->agent->vector_collection;
            $entity = explode('_', $this->intent)[1] ?? 'entity';
            $action = explode('_', $this->intent)[0] ?? 'action';

            Log::info('Processing command job', [
                'intent' => $this->intent,
                'agent_id' => $this->agent->id,
                'data' => $this->data,
            ]);

            if ($action === 'create') {
                $pointId = (string) Str::uuid();
                $payload = $this->data;
                $payload['agent_id'] = (int) $this->agent->id; // Ensure correct agent_id
                $qdrantRepository->insertPoint($collectionName, $pointId, $payload);
                Log::info(ucfirst($entity) . ' point inserted', [
                    'intent' => $this->intent,
                    'agent_id' => $this->agent->id,
                    'point_id' => $pointId,
                    'data' => $payload,
                ]);
            } elseif ($action === 'read') {
                $filters = [
                    'must' => [
                        ['key' => 'agent_id', 'match' => ['value' => (int) $this->agent->id]],
                    ],
                ];
                if (isset($this->data['name'])) {
                    $filters['must'][] = [
                        'key' => 'name',
                        'match' => ['value' => $this->data['name']],
                    ];
                }
                if (isset($this->data['branch_id'])) {
                    $branchId = is_numeric($this->data['branch_id']) ? (int) $this->data['branch_id'] : (string) $this->data['branch_id'];
                    $filters['must'][] = [
                        'key' => 'branch_id',
                        'match' => ['value' => $branchId],
                    ];
                }

                Log::debug('Executing Qdrant search', [
                    'collection' => $collectionName,
                    'filters' => $filters,
                ]);

                $points = $qdrantRepository->searchPoints($collectionName, $filters, 10);
                if (empty($points)) {
                    Log::warning(ucfirst($entity) . ' not found', [
                        'intent' => $this->intent,
                        'agent_id' => $this->agent->id,
                        'filters' => $filters,
                        'collection' => $collectionName,
                    ]);
                    throw new \Exception('No ' . $entity . 's found matching the criteria.');
                }

                Log::info(ucfirst($entity) . ' retrieved', [
                    'intent' => $this->intent,
                    'agent_id' => $this->agent->id,
                    'points' => $points,
                ]);
            } elseif ($action === 'update') {
                if (!isset($this->data['id'])) {
                    throw new \Exception('ID required for update');
                }
                $pointId = $this->data['id'];
                $payload = $this->data;
                unset($payload['id']);
                $payload['agent_id'] = (int) $this->agent->id;
                $point = [
                    'id' => $pointId,
                    'payload' => $payload,
                ];
                $qdrantRepository->upsertPoints($collectionName, [$point]);
                Log::info(ucfirst($entity) . ' point updated', [
                    'intent' => $this->intent,
                    'agent_id' => $this->agent->id,
                    'point_id' => $pointId,
                    'data' => $payload,
                ]);
            } elseif ($action === 'delete') {
                if (!isset($this->data['id'])) {
                    throw new \Exception('ID required for update');
                }
                $pointId = $this->data['id'];
                $qdrantRepository->deletePoint($collectionName, $pointId);
                Log::info(ucfirst($entity) . ' point deleted', [
                    'intent' => $this->intent,
                    'agent_id' => $this->agent->id,
                    'point_id' => $pointId,
                ]);
            } else {
                throw new \Exception("Unsupported intent: {$this->intent}");
            }
        } catch (\Exception $e) {
            Log::error(ucfirst($entity) . ' command job failed', [
                'intent' => $this->intent,
                'agent_id' => $this->agent->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}