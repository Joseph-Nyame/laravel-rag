<?php

use Illuminate\Http\Request;
use App\Services\PromptManager;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RagController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AgentController;


Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);



Route::middleware('auth:sanctum')->group(function () {
    Route::post('/agents', [AgentController::class, 'store']);
    Route::post('/rag/ingest/{agent}', [RagController::class, 'ingest']);
    Route::post('/rag/query/{agent}', [RagController::class, 'query']);

    // only for refining prompts or after reviewing logs or feedback.
    Route::post('/prompts/update', function (Request $request) {
        $request->validate([
            'scenario' => 'required|string',
            'prompt' => 'required|string',
        ]);
        app(PromptManager::class)->updatePrompt($request->scenario, $request->prompt);
        return response()->json(['message' => 'Prompt updated']);
    });
});