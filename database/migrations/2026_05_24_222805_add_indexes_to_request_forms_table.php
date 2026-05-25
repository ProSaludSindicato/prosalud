<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_forms', function (Blueprint $table) {
            $table->index('created_at', 'request_forms_created_at_index');
            $table->index('status', 'request_forms_status_index');
            $table->index('request_type', 'request_forms_request_type_index');
            $table->index(['request_type', 'status', 'created_at'], 'request_forms_type_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('request_forms', function (Blueprint $table) {
            $table->dropIndex('request_forms_created_at_index');
            $table->dropIndex('request_forms_status_index');
            $table->dropIndex('request_forms_request_type_index');
            $table->dropIndex('request_forms_type_status_created_index');
        });
    }
};
