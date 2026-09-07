<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convenio_president_sign_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('scope', 32);
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->boolean('include_errors')->default(false);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('signing')->default(0);
            $table->unsignedInteger('ready_for_review')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->unsignedInteger('completed')->default(0);
            $table->string('status', 32)->default('processing');
            $table->boolean('require_review')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('convenio_president_sign_batches');
    }
};
