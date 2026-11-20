<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cv_outbound_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->nullable()->constrained('cv_knowledge_collections')->restrictOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('cv_conversations')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('title');
            $table->string('path');
            $table->string('mime', 64);
            $table->unsignedInteger('bytes');
            $table->char('digest', 64);
            $table->boolean('approved')->default(false);
            $table->timestamp('created_at');
        });
        Schema::table('cv_messages', function (Blueprint $table) {
            $table->foreignId('outbound_asset_id')->nullable()->constrained('cv_outbound_assets')->restrictOnDelete();
            $table->string('attachment_status', 16)->nullable();
            $table->string('attachment_external_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cv_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('outbound_asset_id');
            $table->dropColumn(['attachment_status', 'attachment_external_id']);
        });
        Schema::dropIfExists('cv_outbound_assets');
    }
};
