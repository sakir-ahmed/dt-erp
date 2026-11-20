<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cv_knowledge_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('cv_knowledge_collections')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('path');
            $table->string('format', 8);
            $table->string('language', 12);
            $table->boolean('enabled')->default(false);
            $table->boolean('public')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->string('status', 12)->default('queued');
            $table->string('error')->nullable();
            $table->timestamps();
        });
        Schema::table('cv_knowledge_entries', function (Blueprint $table) {
            $table->foreignId('document_id')->nullable()->constrained('cv_knowledge_documents')->restrictOnDelete();
            $table->string('embedding_model')->nullable();
            $table->json('embedding')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cv_knowledge_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_id');
            $table->dropColumn(['embedding_model', 'embedding']);
        });
        Schema::dropIfExists('cv_knowledge_documents');
    }
};
