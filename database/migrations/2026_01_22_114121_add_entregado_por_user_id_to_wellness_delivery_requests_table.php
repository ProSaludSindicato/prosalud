<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Agrega el campo para registrar el usuario administrativo que realizó la entrega o cancelación.
     */
    public function up(): void
    {
        Schema::table('wellness_delivery_requests', function (Blueprint $table) {
            $table->foreignId('entregado_por_user_id')
                ->nullable()
                ->after('estado')
                ->comment('ID del usuario administrativo que realizó la entrega o cancelación')
                ->constrained('users')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wellness_delivery_requests', function (Blueprint $table) {
            $table->dropForeign(['entregado_por_user_id']);
            $table->dropColumn('entregado_por_user_id');
        });
    }
};
