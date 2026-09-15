<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temporary_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('file_job_id')->constrained('file_jobs')->cascadeOnDelete();
            $table->foreignUuid('processed_file_id')->nullable()->constrained('processed_files')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique()->index();
            $table->timestamp('expires_at')->index();
            $table->unsignedInteger('max_downloads')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temporary_shares');
    }
};
