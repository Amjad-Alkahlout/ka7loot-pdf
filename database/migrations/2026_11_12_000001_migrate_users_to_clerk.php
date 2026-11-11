<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'clerk_id')) {
                $table->string('clerk_id')->nullable()->unique()->after('id');
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
            if (Schema::hasColumn('users', 'google_id')) {
                $table->dropUnique('users_google_id_unique');
                $table->dropColumn('google_id');
            }
            if (Schema::hasColumn('users', 'avatar')) {
                $table->dropColumn('avatar');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'clerk_id')) {
                $table->dropUnique(['clerk_id']);
                $table->dropColumn('clerk_id');
            }
            $table->string('password')->nullable(false)->change();
            $table->string('google_id')->nullable()->unique();
            $table->string('avatar')->nullable();
        });
    }
};
