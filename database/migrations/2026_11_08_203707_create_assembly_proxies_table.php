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
        Schema::create('assembly_proxies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assembly_id')->constrained('assemblies')->cascadeOnDelete();
            $table->string('proxy_document', 50);
            $table->string('proxy_name')->nullable();
            $table->string('represented_document', 50);
            $table->string('represented_name')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('declared_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->unique(['assembly_id', 'represented_document'], 'unique_represented_per_assembly');
            $table->index(['assembly_id', 'proxy_document']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assembly_proxies');
    }
};
