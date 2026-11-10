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
        Schema::table('stocks', function (Blueprint $table) {
            $table->unsignedInteger('failed_amount')->nullable()->after('synced_at');
            $table->timestamp('failed_at')->nullable()->after('failed_amount');
            $table->string('failed_code')->nullable()->after('failed_at');
            $table->text('failed_message')->nullable()->after('failed_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn(['failed_amount', 'failed_at', 'failed_code', 'failed_message']);
        });
    }
};
