<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cv_ai_settings', function (Blueprint $table) {
            $table->boolean('ollama_enabled')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cv_ai_settings', function (Blueprint $table) {
            $table->dropColumn('ollama_enabled');
        });
    }
};
