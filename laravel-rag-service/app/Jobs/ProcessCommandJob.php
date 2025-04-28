<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Services\DataToVector;
use App\Services\PointService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCommandJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $intent;
    protected $data;
    protected $agent;

    public function __construct(string $intent, array $data, Agent $agent)
    {
        $this->intent = $intent;
        $this->data = $data;
        $this->agent = $agent;
    }

    public function handle(DataToVector $dataToVector, PointService $pointService)
    {
        try {
            $parts = explode('_', $this->intent);
            $action = $parts[0] ?? 'action';
            $entity = $parts[1] ?? 'entity';

            if ($action === 'create') {
                // Create temporary CSV with dynamic headers
                $headers = array_keys($this->data);
                $values = array_map('strval', array_values($this->data));
                $file = new \Illuminate\Http\UploadedFile(
                    storage_path('app/private/temp.csv'),
                    'temp.csv',
                    'text/csv',
                    null,
                    true
                );
                $csvContent = implode(',', $headers) . "\n" . implode(',', $values);
                file_put_contents($file->getPathname(), $csvContent);

                $dataToVector->ingest($this->agent, $file);
                unlink($file->getPathname());

                Log::info(ucfirst($entity) . ' point inserted', [
                    'intent' => $this->intent,
                    'agent_id' => $this->agent->id,
                    'data' => $this->data,
                ]);
            } elseif ($action === 'read') {
                
                Log::info(ucfirst($entity) . ' read intent handled by RAG', [
                    'intent' => $this->intent,
                    'agent_id' => $this->agent->id,
                ]);
            } elseif ($action === 'update') {
                if (!isset($this->data['id'])) {
                    throw new \Exception('ID required for update');
                }
                $pointId = $this->data['id'];
                unset($this->data['id']); // Remove id from payload
                $file = new \Illuminate\Http\UploadedFile(
                    storage_path('app/private/temp.csv'),
                    'temp.csv',
                    'text/csv',
                    null,
                    true
                );
                $headers = array_keys($this->data);
                $values = array_map('strval', array_values($this->data));
                $csvContent = implode(',', $headers) . "\n" . implode(',', $values);
                file_put_contents($file->getPathname(), $csvContent);

                $points = $pointService->createPoints($this->agent, $file, [$this->data]);
                $points[0]['id'] = $pointId; // Reuse existing ID
                $pointService->upsertPoints($this->agent, [$points[0]]);
                unlink($file->getPathname());

                Log::info(ucfirst($entity) . ' point updated', [
                    'intent' => $this->intent,
                    'agent_id' => $this->agent->id,
                    'point_id' => $pointId,
                    'data' => $this->data,
                ]);
            } elseif ($action === 'delete') {
                if (!isset($this->data['id'])) {
                    throw new \Exception('ID required for delete');
                }
                $pointId = $this->data['id'];
                $pointService->deletePoint($this->agent, $pointId);

                Log::info(ucfirst($entity) . ' point deleted', [
                    'intent' => $this->intent,
                    'agent_id' => $this->agent->id,
                    'point_id' => $pointId,
                ]);
            } else {
                Log::info(ucfirst($action) . ' intent not implemented', [
                    'intent' => $this->intent,
                    'agent_id' => $this->agent->id,
                    'entity' => $entity,
                ]);
            }
        } catch (\Exception $e) {
            Log::error(ucfirst($entity) . ' command job failed', [
                'intent' => $this->intent,
                'agent_id' => $this->agent->id,
                'error' => $e->getMessage(),
            ]);
            $this->fail($e);
        }
    }
}