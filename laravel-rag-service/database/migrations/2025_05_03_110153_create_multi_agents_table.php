<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('multi_agents', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique(); // Freeform name, max 100 characters
            $table->json('agent_ids'); // JSON array of agent IDs (2-10)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('multi_agents');
    }
};




