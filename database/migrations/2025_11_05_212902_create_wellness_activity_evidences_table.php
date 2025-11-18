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
        Schema::create('wellness_activity_evidences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('activity_realized_id');
            $table->string('image_url');
            $table->boolean('is_selected_for_gallery')->default(false);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->foreign('activity_realized_id')
                ->references('id')
                ->on('wellness_activity_realized')
                ->onDelete('cascade');

            $table->index('activity_realized_id');
            $table->index('is_selected_for_gallery');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wellness_activity_evidences');
    }
};
