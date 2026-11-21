<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cv_outbound_assets', function (Blueprint $table) {
            // Inbound customer files have no staff owner; they are scoped to their conversation.
            $table->foreignId('owner_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('cv_outbound_assets', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable(false)->change();
        });
    }
};
