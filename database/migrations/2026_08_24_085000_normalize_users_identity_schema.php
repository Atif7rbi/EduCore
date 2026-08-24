<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore identity normalization requires PostgreSQL.'
            );
        }

        $nullCreatedAtCount = DB::table('users')
            ->whereNull('created_at')
            ->count();

        if ($nullCreatedAtCount > 0) {
            throw new RuntimeException(
                "Identity normalization aborted: users.created_at contains {$nullCreatedAtCount} NULL value(s). "
                .'No implicit historical timestamp backfill is permitted.'
            );
        }

        DB::statement('ALTER TABLE users ALTER COLUMN name TYPE TEXT');
        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE TEXT');
        DB::statement('ALTER TABLE users ALTER COLUMN password TYPE TEXT');
        DB::statement('ALTER TABLE users ALTER COLUMN status TYPE TEXT');

        DB::statement('ALTER TABLE users ALTER COLUMN created_at SET NOT NULL');
    }

    public function down(): void
    {
        throw new RuntimeException(
            'EduCore identity normalization is an intentional forward-only migration.'
        );
    }
};
