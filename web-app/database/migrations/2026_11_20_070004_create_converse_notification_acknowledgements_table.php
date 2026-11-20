<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cv_notification_acknowledgements', function (Blueprint $table) {
            $table->foreignId('notification_id')->constrained('cv_notification_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('acknowledged_at');
            $table->primary(['notification_id', 'user_id'], 'cv_notification_ack_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cv_notification_acknowledgements');
    }
};
