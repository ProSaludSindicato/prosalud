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
        Schema::create('assembly_attendances', function (Blueprint $table) {
            $table->id();
            $table->string('document_number', 50);
            $table->string('full_name')->nullable();
            $table->date('issue_date_normalized')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('authenticated_at')->useCurrent();
            $table->timestamps();

            $table->index('document_number');
            $table->index('authenticated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assembly_attendances');
    }
};

