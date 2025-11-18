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
        Schema::create('wellness_activity_realized', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wellness_request_id')->unique();
            $table->date('realized_date');
            $table->string('real_location');
            $table->unsignedInteger('real_attendees_count');
            $table->string('realized_description', 500);
            $table->string('gift_delivered', 255)->nullable();
            $table->string('listado_asistencia_path')->nullable();
            $table->boolean('published_to_gallery')->nullable()->default(null);
            $table->unsignedBigInteger('gallery_event_id')->nullable();
            $table->timestamps();

            $table->foreign('wellness_request_id')
                ->references('id')
                ->on('wellness_requests')
                ->onDelete('cascade');

            $table->foreign('gallery_event_id')
                ->references('id')
                ->on('wellness_events')
                ->onDelete('set null');

            $table->index('wellness_request_id');
            $table->index('gallery_event_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wellness_activity_realized');
    }
};
