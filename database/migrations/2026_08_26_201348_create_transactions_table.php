<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['deposit', 'withdrawal', 'transfer_in', 'transfer_out']);
            $table->bigInteger('amount');            // always positive, in cents
            $table->bigInteger('balance_after');     // balance right after this tx
            $table->uuid('transfer_id')->nullable(); // links the 2 legs of a transfer
            $table->string('idempotency_key')->nullable();
            $table->timestamp('created_at')->useCurrent(); // immutable: no updated_at

            $table->index('account_id');
            $table->index('transfer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
