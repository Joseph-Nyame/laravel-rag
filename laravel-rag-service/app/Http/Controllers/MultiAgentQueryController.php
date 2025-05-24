<?php

namespace App\Http\Controllers;

use App\Models\MultiAgent;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use App\Services\MultiAgentQueryService;
use App\Services\MultiAgent\Orchestrator;

class MultiAgentQueryController extends Controller
{
    protected MultiAgentQueryService $multiAgentQueryService;

    public function __construct(MultiAgentQueryService $multiAgentQueryService,
    private Orchestrator $orchestrator,
  
    
    )
    {
        $this->multiAgentQueryService = $multiAgentQueryService;
    }

    public function query(Request $request, $multiAgentId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'prompt' => 'required|string|max:1000',
                'session_id' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $validated = $validator->validated();

            $multiAgent = MultiAgent::find($multiAgentId);

            if (!$multiAgent) {
                return response()->json(['message' => 'MultiAgent not found'], 404);
            }

            

            $result = $this->multiAgentQueryService->process_query(
                $multiAgent,
                $validated['prompt'],
                $validated['session_id'] ?? null
            );

            return response()->json($result['synthesized_response']);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            // Log the exception for debugging
            // Log::error('Error in MultiAgentQueryController: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred'], 500);
        }
    }

    public function advancedQuery(Request $request, $multiAgentId): JsonResponse
{
    try {
        $validated = $request->validate([
            'prompt' => 'required|string|max:1000',
            'session_id' => 'nullable|string',
            'strategy' => 'nullable|in:auto,direct,broadcast,chained',
        ]);
        
        $multiAgent = MultiAgent::findOrFail($multiAgentId);
        
        $orchestrator = app(Orchestrator::class); 
        $result = $orchestrator->executeQuery(
            $multiAgent,
            $validated['prompt'],
            $validated['session_id'] ?? null,
            $validated['strategy'] ?? 'auto'
        );
        
        return response()->json($result);
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Failed to process query.',
            'message' => $e->getMessage(),
        ], 500);
    }
}
}
