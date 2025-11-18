<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_colors', function (Blueprint $table) {
            $table->string('id')->primary(); // e.g., "AZUL_REY", "VERDE_QUIRURGICO"
            $table->string('label'); // e.g., "Azul Rey", "Verde Quirúrgico"
            $table->string('hex', 7); // e.g., "#1E3A8A"
            $table->timestamps();

            $table->index(['label']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_colors');
    }
};
