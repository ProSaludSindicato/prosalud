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
        Schema::create('supplier_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('supplier_name');
            $table->date('delivery_date');
            $table->integer('total_items')->default(0);
            $table->string('status')->default('pending');
            $table->string('delivery_type')->default('periodic');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['supplier_name']);
            $table->index(['delivery_date']);
            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_deliveries');
    }
};
