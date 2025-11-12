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
        Schema::create('inventory_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('supplier_id')->nullable();
            $table->string('supplier_name')->nullable();

            $table->timestamp('received_at');
            $table->string('document_number')->nullable();
            $table->text('notes')->nullable();

            $table->string('created_by')->nullable();
            $table->uuid('created_by_user_id')->nullable();

            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('total_quantity')->default(0);

            $table->timestamps();

            $table->index('supplier_id');
            $table->index('received_at');
            $table->index('created_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_entries');
    }
};
