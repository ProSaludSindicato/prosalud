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
        Schema::create('certificado_convenio_records', function (Blueprint $table) {
            $table->id();
            $table->string('document_number', 20);
            $table->string('consecutivo', 50)->unique();
            $table->string('storage_path');
            $table->timestamp('generated_at')->useCurrent();

            $table->index('document_number');
            $table->index('consecutivo');
            $table->index(['document_number', 'consecutivo']);
            $table->index('generated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certificado_convenio_records');
    }
};
