<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cv_ai_settings', function (Blueprint $table) {
            $table->json('routing')->nullable();
            $table->string('embedding_model')->nullable();
        });
        Schema::table('cv_contacts', function (Blueprint $table) {
            $table->string('language_preference', 12)->nullable();
            $table->timestamp('language_preference_at')->nullable();
        });
        Schema::table('cv_conversations', function (Blueprint $table) {
            $table->json('memory')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cv_conversations', fn (Blueprint $table) => $table->dropColumn('memory'));
        Schema::table('cv_contacts', fn (Blueprint $table) => $table->dropColumn(['language_preference', 'language_preference_at']));
        Schema::table('cv_ai_settings', fn (Blueprint $table) => $table->dropColumn(['routing', 'embedding_model']));
    }
};
