<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore lesson lifecycle migration requires PostgreSQL.'
            );
        }

        DB::transaction(function (): void {
            DB::statement(
                'ALTER TABLE lessons DROP CONSTRAINT chk_lessons_status'
            );

            DB::table('lessons')
                ->where('status', 'retired')
                ->update([
                    'status' => 'unpublished',
                    'updated_at' => now(),
                ]);

            DB::statement(<<<'SQL'
ALTER TABLE lessons
    ADD CONSTRAINT chk_lessons_status
    CHECK (status IN ('draft', 'published', 'unpublished'))
SQL);
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore lesson lifecycle migration requires PostgreSQL.'
            );
        }

        DB::transaction(function (): void {
            DB::statement(
                'ALTER TABLE lessons DROP CONSTRAINT chk_lessons_status'
            );

            DB::table('lessons')
                ->where('status', 'unpublished')
                ->update([
                    'status' => 'retired',
                    'updated_at' => now(),
                ]);

            DB::statement(<<<'SQL'
ALTER TABLE lessons
    ADD CONSTRAINT chk_lessons_status
    CHECK (status IN ('draft', 'published', 'retired'))
SQL);
        });
    }
};
