<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cv_ai_turns', function (Blueprint $table) {
            $table->json('knowledge_scope')->nullable();
        });
        Schema::table('cv_knowledge_corrections', function (Blueprint $table) {
            $table->text('follow_up')->nullable();
            $table->foreignId('catalog_attachment_id')->nullable()->constrained('cv_outbound_assets')->nullOnDelete();
        });
        Schema::table('cv_conversation_tags', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Keep AI-created tags and their associations when rolling the application back.
        if (! DB::table('cv_conversation_tags')->whereNull('created_by')->exists()) {
            Schema::table('cv_conversation_tags', function (Blueprint $table) {
                $table->unsignedBigInteger('created_by')->nullable(false)->change();
            });
        }
        Schema::table('cv_knowledge_corrections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('catalog_attachment_id');
            $table->dropColumn('follow_up');
        });
        Schema::table('cv_ai_turns', fn (Blueprint $table) => $table->dropColumn('knowledge_scope'));
    }
};
