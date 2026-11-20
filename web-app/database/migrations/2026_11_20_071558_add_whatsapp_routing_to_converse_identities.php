<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cv_contact_identities', function (Blueprint $table) {
            $table->string('whatsapp_user_id', 110)->nullable();
            $table->string('whatsapp_reply_address', 110)->nullable();
            $table->timestamp('whatsapp_address_at')->nullable();
            $table->unique(['channel_account_id', 'whatsapp_user_id'], 'cv_whatsapp_channel_user_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cv_contact_identities', function (Blueprint $table) {
            $table->dropUnique('cv_whatsapp_channel_user_unique');
            $table->dropColumn(['whatsapp_user_id', 'whatsapp_reply_address', 'whatsapp_address_at']);
        });
    }
};
