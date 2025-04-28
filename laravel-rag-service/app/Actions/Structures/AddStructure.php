<?php

namespace App\Actions\Structures;

use App\DTOs\StructureDTO;
use App\Models\Agent;
use App\Models\Structure;

class AddStructure
{
    public function execute(Agent $agent, StructureDTO $structureDTO): Structure
    {
        // Delete existing structure if it exists 
        $agent->structure()?->delete();

        return $agent->structure()->create(
            $structureDTO->toArray()
        );
    }
}