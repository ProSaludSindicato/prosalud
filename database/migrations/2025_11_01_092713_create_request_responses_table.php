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
        Schema::create('request_responses', function (Blueprint $table) {
            $table->id();
            $table->string('request_form_id', 10);
            $table->string('status');
            $table->string('email_subject', 100);
            $table->text('email_body');
            $table->datetime('created_at')->useCurrent();

            $table->foreign('request_form_id')
                  ->references('id')
                  ->on('request_forms')
                  ->onDelete('cascade');

            $table->index('request_form_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('request_responses');
    }
};
