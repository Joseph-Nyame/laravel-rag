<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\CommandHandler;
use App\Services\IntentClass;
use App\Services\RagService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AgentQueryController extends Controller
{
    protected $ragService;
    protected $commandHandler;
    protected $intentClass;

    public function __construct(
        RagService $ragService,
        CommandHandler $commandHandler,
        IntentClass $intentClass
    ) {
        $this->ragService = $ragService;
        $this->commandHandler = $commandHandler;
        $this->intentClass = $intentClass;
    }

    public function query(Request $request,  $agent_id): JsonResponse
    {
       
        $request->validate([
            'prompt' => ['required', 'string', 'max:1000'],
            
        ]);

        $agent = Agent::where('id', $agent_id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Generate or use session ID
        $sessionId = $request->input('session_id', uniqid('chat_'));

        // Retrieve conversation history
        $cacheKey = "chat_history_{$agent_id}_{$sessionId}";
        $conversationHistory = Cache::get($cacheKey, []);

        try {
            // Identify intent
            $agentContext = $agent->name . ' Agent';
            $intent = $this->intentClass->identify($request->prompt, $agentContext);
            $intent = trim($intent, '"\'');
            
            if ($intent === 'rag_query' || strpos($intent, 'read_') === 0) {
                // Handle RAG query or read_entity
                Log::info('Routing to RagService', ['intent' => $intent, 'prompt' => $request->prompt]);
                $response = $this->ragService->chat(
                    agent: $agent,
                    query: $request->prompt,
                    conversationHistory: $conversationHistory
                );

                $currentResponse = $response['response'];
            } 
            else {
                // Handle CRUD command (create, update, delete)
                // return response()->json($intent);
                Log::info('Routing to CommandHandler', ['intent' => $intent, 'prompt' => $request->prompt]);
                $response = $this->commandHandler->handle($request->prompt, $agent);
                $currentResponse = $response['message'];
            }

            // Update conversation history
            $conversationHistory[] = ['role' => 'user', 'content' => $request->prompt];
            $conversationHistory[] = ['role' => 'assistant', 'content' => $currentResponse];

            // Store updated history (24-hour TTL)
            Cache::put($cacheKey, $conversationHistory, now()->addHours(24));

            return response()->json([
                'messages' => $this->ragService->getMessages(),
                'current_response' => $currentResponse,
                // 'context' => $response['context'] ?? null,
                // 'session_id' => $sessionId,
            ], 202);
        } catch (\Exception $e) {
            Log::error('Query processing failed', [
                'prompt' => $request->prompt,
                'agent_id' => $agent->id,
                'intent' => $intent ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            throw ValidationException::withMessages([
                'prompt' => 'Failed to process query: ' . $e->getMessage(),
            ]);
        }
    }
}