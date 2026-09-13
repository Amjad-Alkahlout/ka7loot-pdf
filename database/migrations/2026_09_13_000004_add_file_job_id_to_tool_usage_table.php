<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tool_usage', function (Blueprint $table) {
            $table->uuid('file_job_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('tool_usage', function (Blueprint $table) {
            $table->dropColumn('file_job_id');
        });
    }
};
