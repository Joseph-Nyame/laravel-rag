<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Agent;
use App\Jobs\IngestFileJob;
use Illuminate\Support\Str;
use App\Services\RagService;
use Illuminate\Http\Request;
use App\Services\DataToVector;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RagController extends Controller
{
    protected DataToVector $dataToVector;
    protected RagService $ragService;

    public function __construct(DataToVector $dataToVector, RAGService $ragService)
    {
        $this->dataToVector = $dataToVector;
        $this->ragService = $ragService;
    }

    public function ingest(Request $request, $agent_id): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,sql,json', 'max:10240'],
        ]);

        $agent = Agent::where('id', $agent_id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        try {
                $file = $request->file('file');
                $path = Storage::putFile('uploads', $file);
                $fullPath = Storage::path($path);
                $jobId = (string) Str::uuid();
    
                IngestFileJob::dispatch(
                    $agent,
                    $fullPath,
                    $file->getClientOriginalName()
                );

                // $this->dataToVector->ingest($agent, $file);
    

            Log::info("Ingestion job dispatched", [
                'agent_id' => $agent->id,
                'file' => $file->getClientOriginalName(),
                // 'job_id' => $jobId,
            ]);

            return response()->json([
                'message' => "File is processing for agent {$agent->name}",
                // 'job_id' => $jobId,
            ], 202);
        } catch (Exception $e) {
            // Log::error("Ingestion dispatch failed: {$e->getMessage()}", [
            //     'agent_id' => $agent->id,
            //     'file' => $file->getClientOriginalName(),
            // ]);
            throw ValidationException::withMessages([
                'file' => 'Failed to dispatch ingestion: ' . $e->getMessage(),
            ]);
        }
    }
    public function query(Request $request, $agent_id): JsonResponse
    {
        $request->validate([
            'prompt' => ['required', 'string', 'max:1000'],
            'session_id' => ['sometimes', 'string', 'max:255'],
        ]);

        $agent = Agent::where('id', $agent_id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Generate or use session ID
        $sessionId = $request->input('session_id', uniqid('chat_'));

        // Retrieve conversation history from cache
        $cacheKey = "chat_history_{$agent_id}_{$sessionId}";
        $conversationHistory = Cache::get($cacheKey, []);

        try {
            // Process query with history
            $response = $this->ragService->chat(
                agent: $agent,
                query: $request->prompt,
                conversationHistory: $conversationHistory
            );

            // Update history
            $conversationHistory[] = ['role' => 'user', 'content' => $request->query];
            $conversationHistory[] = ['role' => 'assistant', 'content' => $response['response']];

            // Store updated history (24-hour TTL)
            Cache::put($cacheKey, $conversationHistory, now()->addHours(24));

            return response()->json([
                'messages' => $this->ragService->getMessages(),
                'current_response' => $response['response'],
                // 'context' => $response['context'],
                // 'session_id' => $sessionId,
            ]);
        } catch (Exception $e) {
            throw ValidationException::withMessages([
                'query' => 'Failed to process query: ' . $e->getMessage(),
            ]);
        }
    }
}