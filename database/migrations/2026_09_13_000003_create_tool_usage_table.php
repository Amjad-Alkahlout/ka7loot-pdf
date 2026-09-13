<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_usage', function (Blueprint $table) {
            $table->id();
            $table->string('tool_type', 50)->index();
            $table->string('ip_hash', 64)->index(); // SHA-256 anonymized hash, no raw IP
            $table->boolean('is_successful')->default(true);
            $table->unsignedBigInteger('bytes_processed')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_usage');
    }
};
