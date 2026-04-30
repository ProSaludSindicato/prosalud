<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            // JSON array: ['activo'], ['retirado'], or ['activo','retirado'].
            // Only enforced for access_type = authenticated | restricted.
            $table->json('allowed_affiliate_statuses')->nullable()->after('access_type');
        });

        // Backfill existing rows with the default (active affiliates only).
        DB::table('surveys')->whereNull('allowed_affiliate_statuses')->update([
            'allowed_affiliate_statuses' => json_encode(['activo']),
        ]);
    }

    public function down(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            $table->dropColumn('allowed_affiliate_statuses');
        });
    }
};
