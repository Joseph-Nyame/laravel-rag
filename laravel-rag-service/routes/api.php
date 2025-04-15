<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RagController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AgentController;


Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);



Route::middleware('auth:sanctum')->group(function () {
    Route::post('/agents', [AgentController::class, 'store']);
    Route::post('/rag/ingest/{agent_id}', [RagController::class, 'ingest']);
    Route::post('/rag/query/{agent_id}', [RagController::class, 'query']);
});