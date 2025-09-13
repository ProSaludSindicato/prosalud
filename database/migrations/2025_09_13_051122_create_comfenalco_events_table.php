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
        Schema::create('comfenalco_events', function (Blueprint $table) {
            $table->string('title');
            $table->string('banner_image_url')->unique();
            $table->string('registration_link')->nullable();
            $table->string('category');
            $table->enum('display_size', ['carousel', 'mosaic'])->default('mosaic');
            $table->text('description')->nullable();
            $table->date('created_at')->useCurrent();
            $table->date('registration_deadline')->nullable();
            $table->date('event_date')->nullable();
            $table->boolean('is_visible')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comfenalco_events');
    }
};
