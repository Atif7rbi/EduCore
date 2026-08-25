<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore user role migration requires PostgreSQL.'
            );
        }

        $userCount = DB::table('users')->count();

        if ($userCount > 0) {
            throw new RuntimeException(
                "User role migration aborted: users contains {$userCount} existing row(s). "
                .'No implicit role backfill is permitted.'
            );
        }

        DB::statement(
            'ALTER TABLE users ADD COLUMN role TEXT NOT NULL'
        );

        DB::statement(
            "ALTER TABLE users
             ADD CONSTRAINT chk_users_role
             CHECK (role IN ('student', 'teacher', 'admin'))"
        );
    }

    public function down(): void
    {
        throw new RuntimeException(
            'EduCore user role migration is intentionally forward-only.'
        );
    }
};
