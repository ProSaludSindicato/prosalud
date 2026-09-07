<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            $table->index('created_at', 'convenio_email_tracking_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            $table->dropIndex('convenio_email_tracking_created_at_index');
        });
    }
};
