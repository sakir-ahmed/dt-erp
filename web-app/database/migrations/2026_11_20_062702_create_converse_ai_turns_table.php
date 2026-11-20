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
        Schema::create('cv_ai_turns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbound_message_id')->unique()->constrained('cv_messages')->restrictOnDelete();
            $table->uuid('operation_id')->unique();
            $table->string('language', 12);
            $table->string('intent', 32);
            $table->string('decision', 24);
            $table->string('provider', 24)->nullable();
            $table->string('model')->nullable();
            $table->boolean('fallback')->default(false);
            $table->json('source_ids');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cv_ai_turns');
    }
};
