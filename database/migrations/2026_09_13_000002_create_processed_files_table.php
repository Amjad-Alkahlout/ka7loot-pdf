<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('file_job_id')->constrained('file_jobs')->cascadeOnDelete();
            $table->string('original_name', 255);
            $table->string('stored_filename', 255);
            $table->string('disk', 50)->default('local');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('original_size_bytes')->default(0);
            $table->unsignedBigInteger('processed_size_bytes')->default(0);
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_files');
    }
};
