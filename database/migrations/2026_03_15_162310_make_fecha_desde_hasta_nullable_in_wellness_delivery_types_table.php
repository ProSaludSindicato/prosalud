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
        Schema::table('wellness_delivery_types', function (Blueprint $table) {
            $table->date('fecha_desde')->nullable()->change();
            $table->date('fecha_hasta')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wellness_delivery_types', function (Blueprint $table) {
            $table->date('fecha_desde')->nullable(false)->change();
            $table->date('fecha_hasta')->nullable(false)->change();
        });
    }
};
