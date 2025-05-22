<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Agent;
use App\Models\MultiAgent;
use App\Services\RagService;
use Illuminate\Foundation\Testing\RefreshDatabase; // Correct trait is RefreshDatabase
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Mockery; // For mocking

class MultiAgentQueryTest extends TestCase
{
    use RefreshDatabase; // Use RefreshDatabase for migrations

    // Clean up Mockery after each test
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_multi_agent_query_successful()
    {
        // 1. Setup User
        $user = User::factory()->create();

        // 2. Setup Agents
        $productAgent = Agent::create([
            'user_id' => $user->id,
            'name' => 'Product Agent',
            'description' => 'Provides product information',
            'llm_id' => 1, // Assuming an LLM with ID 1 exists or is not strictly checked here
            'vector_collection' => 'product_collection', // Dummy value
            'prompt_template' => 'Tell me about {{product}}', // Dummy value
        ]);

        $salesAgent = Agent::create([
            'user_id' => $user->id,
            'name' => 'Sales Agent',
            'description' => 'Provides sales information',
            'llm_id' => 1, // Assuming an LLM with ID 1 exists or is not strictly checked here
            'vector_collection' => 'sales_collection', // Dummy value
            'prompt_template' => 'What are the sales for {{region}}', // Dummy value
        ]);

        // 3. Setup MultiAgent
        $multiAgent = MultiAgent::create([
            'user_id' => $user->id, // Assuming MultiAgent has a user_id
            'name' => 'Customer Service Multi-Agent',
            'agent_ids' => [$productAgent->id, $salesAgent->id],
        ]);

        // 4. Mock RagService
        $this->instance(RagService::class, Mockery::mock(RagService::class, function ($mock) use ($productAgent, $salesAgent) {
            $mock->shouldReceive('chat')
                ->with(Mockery::on(function($agent) use ($productAgent) {
                    return $agent->id === $productAgent->id;
                }), Mockery::any(), Mockery::any()) // query, conversationHistory
                ->andReturn(['response' => 'Product details here']);

            $mock->shouldReceive('chat')
                ->with(Mockery::on(function($agent) use ($salesAgent) {
                    return $agent->id === $salesAgent->id;
                }), Mockery::any(), Mockery::any()) // query, conversationHistory
                ->andReturn(['response' => 'Sales figures here']);
        }));
        
        // 5. API Call
        $response = $this->actingAs($user)->postJson("/api/multi-agents/{$multiAgent->id}/query", [
            'prompt' => 'Tell me everything',
        ]);

        // 6. Assertions
        $response->assertStatus(200);

        $response->assertJsonStructure([
            'synthesized_response',
            'individual_responses' => [
                '*' => ['agent_id', 'agent_name', 'response', 'raw_details']
            ],
            // 'session_id' // session_id is in the service response but not added by controller in this version
        ]);
        
        $expectedSynthesizedResponse = "Response from Product Agent: Product details here\nResponse from Sales Agent: Sales figures here";
        $response->assertJsonPath('synthesized_response', $expectedSynthesizedResponse);

        $response->assertJsonCount(2, 'individual_responses');

        // Assert details for Product Agent
        $response->assertJsonFragment([
            'agent_id' => $productAgent->id,
            'agent_name' => 'Product Agent',
            'response' => 'Product details here',
        ]);

        // Assert details for Sales Agent
        $response->assertJsonFragment([
            'agent_id' => $salesAgent->id,
            'agent_name' => 'Sales Agent',
            'response' => 'Sales figures here',
        ]);
    }
}
