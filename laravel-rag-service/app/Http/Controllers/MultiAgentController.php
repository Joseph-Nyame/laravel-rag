<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Services\MultiAgentOrchestrator;
use Illuminate\Validation\ValidationException;

class MultiAgentController extends Controller
{
    protected $orchestrator;

    public function __construct(MultiAgentOrchestrator $orchestrator)
    {
        $this->orchestrator = $orchestrator;
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $multiAgent = $this->orchestrator->createMultiAgent($request->all());
            return response()->json([
                'message' => 'Multi-agent created successfully',
                'data' => $multiAgent->load('relations'),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::error("Failed to create multi-agent: {$e->getMessage()}");
            return response()->json([
                'message' => 'Failed to create multi-agent',
            ], 500);
        }
    }
}

