<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cv_ai_settings', function (Blueprint $table) {
            $table->id();
            $table->string('primary', 12)->nullable();
            $table->boolean('allow_cloud')->nullable();
            $table->string('ollama_model')->nullable();
            $table->string('ollama_fallback_model')->nullable();
            $table->unsignedInteger('ollama_port')->nullable();
            $table->string('openai_model')->nullable();
            $table->text('openai_key')->nullable();
            $table->boolean('openai_key_removed')->default(false);
            $table->json('channels')->nullable();
        });
        DB::table('cv_ai_settings')->insert(['id' => 1]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cv_ai_settings');
    }
};
