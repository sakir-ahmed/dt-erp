<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cv_notification_stream', function (Blueprint $table) {
            $table->id();
        });
        DB::table('cv_notification_stream')->insert(['id' => 1]);

        Schema::create('cv_notification_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('cv_conversations')->cascadeOnDelete();
            $table->string('type', 24);
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cv_notification_events');
        Schema::dropIfExists('cv_notification_stream');
    }
};
