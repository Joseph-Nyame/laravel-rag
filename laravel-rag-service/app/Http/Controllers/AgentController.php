<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class AgentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'vector_collection' => ['required', 'string', 'max:255', 'unique:agents,vector_collection', 'regex:/^[a-zA-Z0-9_-]+$/'],
        ]);

        $collectionName = Str::slug($request->vector_collection);

        // Create Qdrant collection
        $response = Http::post(config('services.qdrant.host') . '/collections/' . $collectionName, [
            'vectors' => [
                'size' => 1536, // OpenAI text-embedding-3-small
                'distance' => 'Cosine',
            ],
        ]);

        if ($response->failed()) {
            throw ValidationException::withMessages([
                'vector_collection' => 'Failed to create vector collection.',
            ]);
        }

        $agent = $request->user()->agents()->create([
            'name' => $request->name,
            'vector_collection' => $collectionName,
        ]);

        return response()->json([
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'vector_collection' => $agent->vector_collection,
            ],
        ], 201);
    }
}