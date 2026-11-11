<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_file_quotas', function (Blueprint $table) {
            $table->id();
            $table->date('usage_date');
            $table->string('subject_type', 10);
            $table->string('subject_key', 100);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('reserved_count')->default(0);
            $table->unsignedInteger('consumed_count')->default(0);
            $table->unsignedInteger('released_count')->default(0);
            $table->timestamps();
            $table->unique(['usage_date', 'subject_type', 'subject_key']);
            $table->index(['usage_date', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_file_quotas');
    }
};
