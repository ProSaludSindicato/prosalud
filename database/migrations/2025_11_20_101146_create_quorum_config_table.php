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
        Schema::create('quorum_config', function (Blueprint $table) {
            $table->id();
            $table->integer('total_delegates')->default(100);
            $table->integer('present_delegates')->default(0);
            $table->integer('required_percentage')->default(50);
            $table->boolean('verified')->default(false);
            $table->string('assembly_id', 255)->nullable()->index();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quorum_config');
    }
};
