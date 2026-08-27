<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clear any existing tokens (they'd be invalid anyway)
        DB::table('personal_access_tokens')->truncate();

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // Change tokenable_id from bigint to string so it can hold UUIDs
            $table->string('tokenable_id')->change();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('tokenable_id')->change();
        });
    }
};
