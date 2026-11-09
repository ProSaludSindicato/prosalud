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
        Schema::table('assembly_votes', function (Blueprint $table) {
            $table->string('cast_by_document', 50)->nullable()->after('voter_name');
            $table->foreignId('proxy_id')->nullable()->after('cast_by_document')
                ->constrained('assembly_proxies')->nullOnDelete();

            $table->index('cast_by_document');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assembly_votes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proxy_id');
            $table->dropIndex(['cast_by_document']);
            $table->dropColumn('cast_by_document');
        });
    }
};
