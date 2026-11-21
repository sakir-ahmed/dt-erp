<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cv_conversation_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('cv_conversation_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('cv_conversations')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('cv_conversation_tags')->cascadeOnDelete();
            $table->unique(['conversation_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cv_conversation_tag');
        Schema::dropIfExists('cv_conversation_tags');
    }
};
