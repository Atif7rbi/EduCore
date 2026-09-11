<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION educore_subject_catalog_immutability()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.code IS DISTINCT FROM NEW.code THEN
        RAISE EXCEPTION
            'Subject.code is immutable after INSERT'
            USING ERRCODE = '23514';
    END IF;

    IF OLD.code IS NOT NULL
       AND OLD.name IS DISTINCT FROM NEW.name THEN
        RAISE EXCEPTION
            'Canonical Subject.name is immutable'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_subjects_catalog_immutability
BEFORE UPDATE ON subjects
FOR EACH ROW
EXECUTE PROCEDURE educore_subject_catalog_immutability();


CREATE OR REPLACE FUNCTION educore_education_stage_identity_immutability()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.code IS DISTINCT FROM NEW.code THEN
        RAISE EXCEPTION
            'EducationStage.code is immutable after INSERT'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_education_stages_identity_immutability
BEFORE UPDATE ON education_stages
FOR EACH ROW
EXECUTE PROCEDURE educore_education_stage_identity_immutability();


CREATE OR REPLACE FUNCTION educore_curriculum_stage_immutability()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.education_stage_id
       IS DISTINCT FROM NEW.education_stage_id THEN
        RAISE EXCEPTION
            'Curriculum.education_stage_id is immutable after INSERT'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_curricula_education_stage_immutability
BEFORE UPDATE ON curricula
FOR EACH ROW
EXECUTE PROCEDURE educore_curriculum_stage_immutability();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS trg_curricula_education_stage_immutability
    ON curricula;

DROP FUNCTION IF EXISTS educore_curriculum_stage_immutability();


DROP TRIGGER IF EXISTS trg_education_stages_identity_immutability
    ON education_stages;

DROP FUNCTION IF EXISTS educore_education_stage_identity_immutability();


DROP TRIGGER IF EXISTS trg_subjects_catalog_immutability
    ON subjects;

DROP FUNCTION IF EXISTS educore_subject_catalog_immutability();
SQL);
    }
};
