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
            $table->char('pdf_original_sha256', 64)->nullable()->after('pdf_final_path');
            $table->char('pdf_firmado_afiliado_sha256', 64)->nullable()->after('pdf_original_sha256');
            $table->string('text_integrity_status', 32)->nullable()->after('pdf_firmado_afiliado_sha256');
            $table->string('signed_ip', 45)->nullable()->after('firmado_afiliado_at');
            $table->text('signed_user_agent')->nullable()->after('signed_ip');
            $table->json('signing_audit_log')->nullable()->after('signed_user_agent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            $table->dropColumn([
                'pdf_original_sha256',
                'pdf_firmado_afiliado_sha256',
                'text_integrity_status',
                'signed_ip',
                'signed_user_agent',
                'signing_audit_log',
            ]);
        });
    }
};
