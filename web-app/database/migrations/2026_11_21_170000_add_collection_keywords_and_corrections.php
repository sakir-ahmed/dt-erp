<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cv_knowledge_collections', function (Blueprint $table) {
            // Keywords route a customer message to this collection's knowledge first.
            $table->json('keywords')->nullable();
        });
        Schema::create('cv_knowledge_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->nullable()->constrained('cv_knowledge_collections')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('question', 500);
            $table->text('answer');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cv_knowledge_corrections');
        Schema::table('cv_knowledge_collections', function (Blueprint $table) {
            $table->dropColumn('keywords');
        });
    }
};
