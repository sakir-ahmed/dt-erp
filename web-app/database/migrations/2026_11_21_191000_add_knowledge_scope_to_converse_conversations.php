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
        Schema::table('cv_conversations', function (Blueprint $table) {
            $table->string('knowledge_scope_mode', 12)->default('automatic');
            $table->json('knowledge_collection_ids')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cv_conversations', function (Blueprint $table) {
            $table->dropColumn(['knowledge_scope_mode', 'knowledge_collection_ids']);
        });
    }
};
