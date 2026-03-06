<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Permite registro interno (affiliate-lookup) sin fecha de expedición.
     */
    public function up(): void
    {
        Schema::table('wellness_delivery_requests', function (Blueprint $table) {
            $table->string('fecha_expedicion', 20)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wellness_delivery_requests', function (Blueprint $table) {
            $table->string('fecha_expedicion', 20)->nullable(false)->change();
        });
    }
};
