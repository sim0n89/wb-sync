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
        Schema::create('stock_upload_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_upload_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('chrt_id');
            $table->unsignedInteger('amount');
            $table->timestamps();

            $table->index('chrt_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_upload_items');
    }
};
