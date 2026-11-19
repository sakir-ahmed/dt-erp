<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('stock_milliunits')->default(0);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('operation_id');
            $table->bigInteger('delta_milliunits');
            $table->unsignedBigInteger('balance_milliunits');
            $table->string('reason');
            $table->timestamp('created_at');
            $table->unique(['product_id', 'operation_id']);
            $table->index(['product_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('stock_milliunits');
        });
    }
};
