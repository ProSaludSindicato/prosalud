<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Las solicitudes nuevas usan wellness_delivery_type_id; tipo_entrega queda para legacy.
     */
    public function up(): void
    {
        Schema::table('wellness_delivery_requests', function (Blueprint $table) {
            $table->string('tipo_entrega', 50)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wellness_delivery_requests', function (Blueprint $table) {
            $table->string('tipo_entrega', 50)->nullable(false)->change();
        });
    }
};
