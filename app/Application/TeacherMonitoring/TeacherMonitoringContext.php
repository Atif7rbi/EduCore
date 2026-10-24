<?php

namespace App\Application\TeacherMonitoring;

final readonly class TeacherMonitoringContext
{
    public function __construct(
        public string $teacherUserId,
        public string $studentUserId,
        public string $learnerProfileId,
        public string $studentEnrollmentId,
        public string $teacherSubjectAssignmentId,
        public string $subjectId,
        public string $curriculumId,
        public string $curriculumVersionId,
    ) {}
}
