<?php

namespace App\Actions\Structures;

use App\DTOs\StructureDTO;
use App\Models\Agent;
use App\Models\Structure;
use Illuminate\Support\Facades\Log;

class ManageStructure
{
    public function updateStructure(Agent $agent, StructureDTO $dto): ?Structure
    {
        try {
            // Save schema directly
            $structure = $agent->structure()->updateOrCreate(
                ['agent_id' => $agent->id],
                ['schema' => $dto->toArray()['schema']]
            );

            Log::info('Structure updated for agent', [
                'agent_id' => $agent->id,
                'schema' => $dto->toArray(),
            ]);

            return $structure;
        } catch (\Exception $e) {
            Log::error('Failed to update structure', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}