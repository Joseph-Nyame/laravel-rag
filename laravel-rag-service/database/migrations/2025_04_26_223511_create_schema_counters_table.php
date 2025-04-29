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
        Schema::create('schema_counters', function (Blueprint $table) {
            $table->id();
            $table->string('agent_id');
            $table->string('field');
            $table->unsignedBigInteger('value')->default(0);
            $table->unique(['agent_id', 'field']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schema_counters');
    }
};
