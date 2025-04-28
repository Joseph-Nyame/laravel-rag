<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\DTOs\StructureDTO;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Actions\Structures\AddStructure;
use App\Actions\Structures\ManageStructure;

class StructureController extends Controller
{
    protected $client;

    public function __construct(
        public ManageStructure $manageStructure
    )
    {
       
    }
    // public function store(Request $request, Agent $agent, AddStructure $action)
    // {
    //     $structureDTO = StructureDTO::fromRequest($request);
    //     $structure = $action->execute(agent: $agent, structureDTO: $structureDTO);

    //     return response()->json([
    //         'message' => 'Structure created successfully',
    //         'data' => $structure
    //     ], 201);
    // }

    // public function update(Request $request, Agent $agent): JsonResponse
    // {
    //     // Validate and create DTO
    //     $dto = StructureDTO::fromRequest($request);
    //   $structure =  $this->manageStructure->updateStructure($agent, $dto);
        

    //     return response()->json($structure, 200);
    // }

  
    
}