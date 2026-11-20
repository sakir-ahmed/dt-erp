<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cv_knowledge_document_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('cv_knowledge_documents')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('path');
            $table->string('format', 8);
            $table->string('language', 12);
            $table->timestamp('replaced_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cv_knowledge_document_revisions');
    }
};
