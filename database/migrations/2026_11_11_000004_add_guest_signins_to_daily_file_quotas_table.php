<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_file_quotas', function (Blueprint $table) {
            $table->unsignedInteger('guest_sign_in_count')->default(0)->after('released_count');
        });
    }

    public function down(): void
    {
        Schema::table('daily_file_quotas', function (Blueprint $table) {
            $table->dropColumn('guest_sign_in_count');
        });
    }
};
