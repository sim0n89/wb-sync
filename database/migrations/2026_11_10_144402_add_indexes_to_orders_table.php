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
        Schema::table('orders', function (Blueprint $table) {
            $table->index('rid');
            $table->index(['shop_id', 'wb_created_at']);
            $table->index(['shop_id', 'nm_id']);
            $table->index(['shop_id', 'article']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['rid']);
            $table->dropIndex(['shop_id', 'wb_created_at']);
            $table->dropIndex(['shop_id', 'nm_id']);
            $table->dropIndex(['shop_id', 'article']);
        });
    }
};
