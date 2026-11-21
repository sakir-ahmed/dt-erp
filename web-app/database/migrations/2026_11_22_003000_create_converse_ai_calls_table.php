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
        Schema::create('cv_ai_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained('cv_conversations')->nullOnDelete();
            $table->string('provider', 16);
            $table->string('model', 255);
            $table->string('operation', 16);
            $table->string('origin', 24);
            $table->string('billing', 12);
            $table->string('status', 16);
            $table->string('agent', 40)->nullable();
            $table->string('workflow', 80)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('total_tokens')->nullable();
            $table->decimal('reported_cost_usd', 18, 10)->nullable();
            $table->longText('detail');
            $table->timestamp('called_at')->index();
            $table->index(['provider', 'called_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cv_ai_calls');
    }
};
