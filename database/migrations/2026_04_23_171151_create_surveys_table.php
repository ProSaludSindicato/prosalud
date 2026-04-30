<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $table) {
            $table->string('id', 10)->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'active', 'closed'])->default('draft');
            $table->enum('access_type', ['public', 'authenticated', 'restricted'])->default('public');
            $table->boolean('requires_signature')->default(false);
            $table->boolean('allows_multiple_responses')->default(true);
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('access_type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surveys');
    }
};
