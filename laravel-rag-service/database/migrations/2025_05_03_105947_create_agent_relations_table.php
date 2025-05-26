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
        Schema::create('agent_relations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_agent_id');
            $table->unsignedBigInteger('target_agent_id');
            $table->string('join_key');
            $table->text('description')->nullable();
            $table->float('confidence');
            $table->timestamps();

            $table->foreign('source_agent_id')
                  ->references('id')
                  ->on('agents')
                  ->onDelete('cascade');

            $table->foreign('target_agent_id')
                  ->references('id')
                  ->on('agents')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_relations');
    }
};
