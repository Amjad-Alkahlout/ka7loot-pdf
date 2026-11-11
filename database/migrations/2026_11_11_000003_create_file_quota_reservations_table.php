<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_quota_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('file_job_id')->unique()->constrained('file_jobs')->cascadeOnDelete();
            $table->date('usage_date');
            $table->string('subject_type', 10);
            $table->string('subject_key', 100);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('units');
            $table->unsignedInteger('consumed_units')->default(0);
            $table->unsignedInteger('released_units')->default(0);
            $table->string('status', 20)->default('reserved')->index();
            $table->timestamps();
            $table->index(['usage_date', 'subject_type', 'subject_key'], 'quota_reservation_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_quota_reservations');
    }
};
