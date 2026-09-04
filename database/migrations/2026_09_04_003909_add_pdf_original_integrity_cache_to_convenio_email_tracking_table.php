<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            $table->unsignedSmallInteger('pdf_original_page_count')->nullable()->after('pdf_original_sha256');
            $table->char('pdf_original_text_fingerprint', 64)->nullable()->after('pdf_original_page_count');
        });
    }

    public function down(): void
    {
        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            $table->dropColumn([
                'pdf_original_page_count',
                'pdf_original_text_fingerprint',
            ]);
        });
    }
};
