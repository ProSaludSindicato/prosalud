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
        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            $table->string('signing_token_hash', 64)->nullable()->unique()->after('parent_tracking_id');
            $table->timestamp('token_expires_at')->nullable()->after('signing_token_hash');
            $table->string('signing_estado', 32)->nullable()->index()->after('token_expires_at');
            $table->string('pdf_original_path', 500)->nullable()->after('signing_estado');
            $table->string('pdf_firmado_afiliado_path', 500)->nullable()->after('pdf_original_path');
            $table->string('pdf_final_path', 500)->nullable()->after('pdf_firmado_afiliado_path');
            $table->timestamp('firmado_afiliado_at')->nullable()->after('pdf_final_path');
            $table->timestamp('firmado_presidente_at')->nullable()->after('firmado_afiliado_at');
            $table->timestamp('rechazado_at')->nullable()->after('firmado_presidente_at');
            $table->text('motivo_rechazo')->nullable()->after('rechazado_at');
            $table->string('sede', 255)->nullable()->index()->after('motivo_rechazo');
            $table->index(['signing_estado', 'token_expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            $table->dropIndex(['signing_estado', 'token_expires_at']);
            $table->dropUnique(['signing_token_hash']);
        });

        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            $table->dropColumn([
                'signing_token_hash',
                'token_expires_at',
                'signing_estado',
                'pdf_original_path',
                'pdf_firmado_afiliado_path',
                'pdf_final_path',
                'firmado_afiliado_at',
                'firmado_presidente_at',
                'rechazado_at',
                'motivo_rechazo',
                'sede',
            ]);
        });
    }
};
