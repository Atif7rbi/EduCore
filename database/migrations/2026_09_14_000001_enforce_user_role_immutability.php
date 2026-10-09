<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore user role immutability requires PostgreSQL.'
            );
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION educore_enforce_user_role_immutable()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.role IS DISTINCT FROM OLD.role THEN
        RAISE EXCEPTION 'users.role is immutable after provisioning'
            USING
                ERRCODE = '23514',
                CONSTRAINT = 'chk_users_role_immutable';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_users_role_immutable
BEFORE UPDATE OF role ON users
FOR EACH ROW
EXECUTE PROCEDURE educore_enforce_user_role_immutable();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'EduCore user role immutability migration is intentionally forward-only.'
        );
    }
};
