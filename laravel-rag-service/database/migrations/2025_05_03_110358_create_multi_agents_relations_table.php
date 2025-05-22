<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('multi_agent_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('multi_agent_id')->constrained('multi_agents')->onDelete('cascade');
            $table->foreignId('source_agent_id')->constrained('agents')->onDelete('cascade');
            $table->foreignId('target_agent_id')->constrained('agents')->onDelete('cascade');
            $table->string('join_key');
            $table->text('description')->nullable();
            $table->float('suggested_confidence')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('multi_agent_relations');
    }
};