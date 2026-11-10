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
        Schema::table('stock_uploads', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('error');
            $table->index(['shop_id', 'status', 'updated_at']);
        });

        Schema::table('stock_upload_items', function (Blueprint $table) {
            $table->unsignedInteger('wb_amount')->nullable()->after('amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_upload_items', function (Blueprint $table) {
            $table->dropColumn('wb_amount');
        });

        Schema::table('stock_uploads', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'status', 'updated_at']);
            $table->dropColumn('verified_at');
        });
    }
};
