<?php

namespace App\Application\TeacherAuthoring;

final readonly class LockedTeacherCurriculumAuthority
{
    public function __construct(
        public string $teacherUserId,
        public string $subjectId,
        public string $teacherSubjectAssignmentId,
        public string $curriculumId,
    ) {}
}
