<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cv_channel_accounts', function (Blueprint $table) {
            $table->text('send_token')->nullable();
        });

        Schema::table('cv_contacts', function (Blueprint $table) {
            $table->timestamp('opted_out_at')->nullable();
        });

        Schema::table('cv_messages', function (Blueprint $table) {
            $table->uuid('operation_id')->nullable();
            $table->string('delivery_status', 16)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unique(['conversation_id', 'operation_id'], 'cv_messages_operation_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cv_messages', function (Blueprint $table) {
            $table->dropUnique('cv_messages_operation_unique');
            $table->dropConstrainedForeignId('sent_by');
            $table->dropColumn(['operation_id', 'delivery_status']);
        });

        Schema::table('cv_contacts', fn (Blueprint $table) => $table->dropColumn('opted_out_at'));
        Schema::table('cv_channel_accounts', fn (Blueprint $table) => $table->dropColumn('send_token'));
    }
};
