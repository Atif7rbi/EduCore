<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore Teacher provisioning requires PostgreSQL.'
            );
        }

        Schema::create(
            'teacher_account_provisionings',
            function (Blueprint $table): void {
                $table->uuid('teacher_user_id')->primary();

                $table->uuid('provisioned_by_user_id');

                $table->timestampTz(
                    'setup_completed_at'
                )->nullable();

                $table->timestampTz('created_at');

                $table->foreign('teacher_user_id')
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();

                $table->foreign('provisioned_by_user_id')
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();

                $table->index('provisioned_by_user_id');
            }
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION educore_guard_teacher_account_provisioning()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    teacher_record RECORD;
    actor_record RECORD;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION
            'TeacherAccountProvisioning history cannot be deleted'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'INSERT' THEN
        /*
         * Canonical provisioning lock order:
         * Admin actor -> Teacher.
         */
        SELECT role, status
        INTO actor_record
        FROM users
        WHERE id = NEW.provisioned_by_user_id
        FOR UPDATE;

        IF NOT FOUND
           OR actor_record.role <> 'admin'
           OR actor_record.status <> 'active' THEN
            RAISE EXCEPTION
                'Teacher provisioning requires an active ADMIN actor'
                USING ERRCODE = '23514';
        END IF;

        SELECT role, status
        INTO teacher_record
        FROM users
        WHERE id = NEW.teacher_user_id
        FOR UPDATE;

        IF NOT FOUND
           OR teacher_record.role <> 'teacher'
           OR teacher_record.status <> 'disabled' THEN
            RAISE EXCEPTION
                'Teacher provisioning requires a disabled TEACHER User'
                USING ERRCODE = '23514';
        END IF;

        IF NEW.setup_completed_at IS NOT NULL THEN
            RAISE EXCEPTION
                'Teacher provisioning cannot start completed'
                USING ERRCODE = '23514';
        END IF;

        RETURN NEW;
    END IF;

    IF NEW.teacher_user_id IS DISTINCT FROM OLD.teacher_user_id
       OR NEW.provisioned_by_user_id
            IS DISTINCT FROM OLD.provisioned_by_user_id
       OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION
            'Teacher provisioning identity is immutable'
            USING ERRCODE = '23514';
    END IF;

    IF OLD.setup_completed_at IS NOT NULL
       AND NEW.setup_completed_at
            IS DISTINCT FROM OLD.setup_completed_at THEN
        RAISE EXCEPTION
            'Teacher setup completion is immutable'
            USING ERRCODE = '23514';
    END IF;

    IF OLD.setup_completed_at IS NULL
       AND NEW.setup_completed_at IS NOT NULL THEN

        SELECT role, status
        INTO teacher_record
        FROM users
        WHERE id = NEW.teacher_user_id
        FOR UPDATE;

        IF NOT FOUND
           OR teacher_record.role <> 'teacher'
           OR teacher_record.status <> 'active' THEN
            RAISE EXCEPTION
                'Teacher setup completion requires an active TEACHER User'
                USING ERRCODE = '23514';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_teacher_account_provisionings_integrity
BEFORE INSERT OR UPDATE OR DELETE
ON teacher_account_provisionings
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_teacher_account_provisioning();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'EduCore Teacher provisioning foundation is intentionally forward-only.'
        );
    }
};
