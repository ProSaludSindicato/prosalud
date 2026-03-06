<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Las solicitudes nuevas usan el tipo activo para la fecha; las antiguas siguen con tipo_entrega/tipo_entrega_descripcion.
     */
    public function up(): void
    {
        Schema::table('wellness_delivery_requests', function (Blueprint $table) {
            $table->foreignId('wellness_delivery_type_id')
                ->nullable()
                ->after('id')
                ->constrained('wellness_delivery_types')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wellness_delivery_requests', function (Blueprint $table) {
            $table->dropForeign(['wellness_delivery_type_id']);
        });
    }
};
