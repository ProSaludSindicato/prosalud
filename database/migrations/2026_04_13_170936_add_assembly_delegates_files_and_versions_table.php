<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assemblies', function (Blueprint $table) {
            $table->string('delegates_file_path')->nullable()->after('is_active');
            $table->string('delegates_file_disk', 50)->default('prosalud-private')->after('delegates_file_path');
        });

        Schema::create('assembly_delegate_file_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assembly_id')->constrained('assemblies')->cascadeOnDelete();
            $table->string('storage_path', 512);
            $table->string('disk', 50)->default('prosalud-private');
            $table->string('original_filename', 255)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('row_count')->default(0);
            $table->timestamps();

            $table->index(['assembly_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assembly_delegate_file_versions');

        Schema::table('assemblies', function (Blueprint $table) {
            $table->dropColumn(['delegates_file_path', 'delegates_file_disk']);
        });
    }
};
