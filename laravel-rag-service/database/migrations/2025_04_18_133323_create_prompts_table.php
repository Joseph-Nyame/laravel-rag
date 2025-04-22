<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePromptsTable extends Migration
{
    public function up()
    {
        Schema::create('prompts', function (Blueprint $table) {
            $table->id();
            $table->string('scenario')->unique();
            $table->text('prompt');
            $table->boolean('is_auto_generated')->default(false);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('prompts');
    }
}