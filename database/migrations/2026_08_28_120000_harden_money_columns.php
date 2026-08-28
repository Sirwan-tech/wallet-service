<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Balances, amounts, and balance snapshots are never valid when negative.
     * The service validates this first; unsigned columns make the database a
     * final line of defence if code outside the service is ever introduced.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('balance')->default(0)->change();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('amount')->change();
            $table->unsignedBigInteger('balance_after')->change();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->bigInteger('amount')->change();
            $table->bigInteger('balance_after')->change();
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->bigInteger('balance')->default(0)->change();
        });
    }
};
