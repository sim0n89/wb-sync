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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('wb_order_id');
            $table->string('rid')->nullable();
            $table->string('article')->nullable();
            $table->unsignedBigInteger('nm_id')->nullable();
            $table->unsignedBigInteger('chrt_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedInteger('price');
            $table->unsignedSmallInteger('currency_code')->nullable();
            $table->timestamp('wb_created_at');
            $table->json('payload');
            $table->timestamps();

            $table->unique(['shop_id', 'wb_order_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
