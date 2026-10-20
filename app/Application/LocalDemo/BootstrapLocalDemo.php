<?php

namespace App\Application\LocalDemo;

use App\Application\Assessment\PublishAssessmentItem;
use App\Application\Assessment\ReleaseAssessmentItemRevision;
use App\Application\Curriculum\CreateOwnedCurriculum;
use App\Application\Curriculum\PublishCurriculumVersion;
use App\Application\Enrollment\AcceptStudentEnrollment;
use App\Application\Enrollment\RequestStudentEnrollment;
use App\Application\Exam\BuildExamGeneration;
use App\Application\Identity\ProvisionTeacher;
use App\Application\Identity\RedeemPasswordResetToken;
use App\Application\Identity\RegisterStudent;
use App\Application\Learning\PublishLesson;
use App\Application\Learning\ReleaseLessonRevision;
use App\Application\Support\TransactionManager;
use App\Application\Taxonomy\CreateSkill;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Application\TeacherAuthoring\ManageTeacherCurriculumAuthoring;
use App\Application\TeacherAuthoring\ManageTeacherLessonAssessmentAuthoring;
use App\Application\TeacherAuthoring\ManageTeacherPracticeExamAuthoring;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use RuntimeException;

class BootstrapLocalDemo
{
    public const ADMIN_EMAIL = 'local.admin@educore.test';

    public const TEACHER_EMAIL = 'local.teacher@educore.test';

    public const STUDENT_EMAIL = 'local.student@educore.test';

    /** @var array<int, string> */
    public const EMAILS = [
        self::ADMIN_EMAIL,
        self::TEACHER_EMAIL,
        self::STUDENT_EMAIL,
    ];

    public function __construct(
        private readonly LocalDemoEnvironmentGuard $environment,
        private readonly TransactionManager $transactions,
        private readonly ProvisionTeacher $provisionTeacher,
        private readonly RedeemPasswordResetToken $redeemPasswordResetToken,
        private readonly RegisterStudent $registerStudent,
        private readonly AssignTeacherSubject $assignTeacherSubject,
        private readonly RequestStudentEnrollment $requestStudentEnrollment,
        private readonly AcceptStudentEnrollment $acceptStudentEnrollment,
        private readonly CreateOwnedCurriculum $createOwnedCurriculum,
        private readonly CreateSkill $createSkill,
        private readonly ManageTeacherCurriculumAuthoring $curriculumAuthoring,
        private readonly ManageTeacherLessonAssessmentAuthoring $lessonAssessmentAuthoring,
        private readonly ManageTeacherPracticeExamAuthoring $practiceExamAuthoring,
        private readonly ReleaseLessonRevision $releaseLessonRevision,
        private readonly PublishLesson $publishLesson,
        private readonly ReleaseAssessmentItemRevision $releaseAssessmentRevision,
        private readonly PublishAssessmentItem $publishAssessment,
        private readonly BuildExamGeneration $buildExamGeneration,
        private readonly PublishCurriculumVersion $publishCurriculumVersion,
    ) {}

    /** @return array<string, string> */
    public function execute(string $password): array
    {
        $this->environment->assertSafe();

        $passwordValidator = Validator::make(
            ['password' => $password],
            [
                'password' => [
                    'required',
                    'string',
                    PasswordRule::min(12)
                        ->letters()
                        ->mixedCase()
                        ->numbers()
                        ->symbols(),
                ],
            ],
        );

        if ($passwordValidator->fails()) {
            throw new RuntimeException('The demo password does not meet local bootstrap requirements.');
        }

        return $this->transactions->run(function () use ($password): array {
            DB::select('SELECT pg_advisory_xact_lock(?)', [1322968395]);
            DB::statement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');

            if (User::query()->where('role', 'admin')->lockForUpdate()->exists()) {
                throw new RuntimeException('The initial local Admin has already been established.');
            }

            if (
                User::query()
                    ->whereIn(DB::raw('LOWER(email)'), self::EMAILS)
                    ->lockForUpdate()
                    ->exists()
            ) {
                throw new RuntimeException('A reserved local demo identity already exists.');
            }

            $admin = User::query()->create([
                'name' => 'EduCore Local Admin',
                'email' => self::ADMIN_EMAIL,
                'password' => Hash::make($password),
                'status' => 'active',
                'role' => 'admin',
            ]);

            $teacher = $this->provisionTeacher->execute(
                $admin->id,
                'EduCore Local Teacher',
                self::TEACHER_EMAIL,
            );
            $teacherToken = Password::broker()->createToken($teacher);
            $teacher = $this->redeemPasswordResetToken->execute(
                self::TEACHER_EMAIL,
                $teacherToken,
                $password,
            );

            $student = $this->registerStudent->register([
                'name' => 'EduCore Local Student',
                'email' => self::STUDENT_EMAIL,
                'password' => $password,
            ]);

            $subject = Subject::query()
                ->where('code', 'mathematics')
                ->where('status', 'active')
                ->first();
            if ($subject === null || ! $subject->isCanonical()) {
                throw (new ModelNotFoundException)->setModel(Subject::class, ['mathematics']);
            }

            $assignment = $this->assignTeacherSubject->execute(
                $admin->id,
                $teacher->id,
                $subject->id,
                (string) Str::uuid(),
                'Local demonstration mathematics assignment',
            );

            $curriculum = $this->createOwnedCurriculum->execute(
                $teacher->id,
                $assignment->id,
                'Local Mathematics Demonstration',
                null,
            );
            $versionOne = $this->curriculumAuthoring->createVersion(
                $teacher->id,
                $assignment->id,
                $curriculum->id,
                1,
                'Published demonstration',
            );

            $skill = $this->createSkill->execute(
                $admin->id,
                'Local arithmetic fluency',
                'A local-only demonstration skill.',
            );
            $topic = $this->curriculumAuthoring->createTopic(
                $teacher->id,
                $assignment->id,
                $curriculum->id,
                $versionOne->id,
                'Whole-number addition',
                1,
            );
            $placement = $this->curriculumAuthoring->createPlacement(
                $teacher->id,
                $assignment->id,
                $curriculum->id,
                $versionOne->id,
                $skill->id,
            );
            $this->curriculumAuthoring->createHomeTopic(
                $teacher->id,
                $assignment->id,
                $curriculum->id,
                $versionOne->id,
                $placement->id,
                $topic->id,
            );

            $lesson = $this->lessonAssessmentAuthoring->createLesson(
                $teacher->id,
                $assignment->id,
                $curriculum->id,
                $versionOne->id,
                'Adding whole numbers',
                'A concise published demonstration lesson.',
                1,
            );
            $lessonRevision = $this->lessonAssessmentAuthoring->createLessonRevision(
                $teacher->id,
                $assignment->id,
                $curriculum->id,
                $versionOne->id,
                $lesson->id,
                1,
                $topic->id,
                ['blocks' => [['type' => 'paragraph', 'text' => 'Add by combining place values.']]],
                1,
            );
            $this->lessonAssessmentAuthoring->createLessonRevisionSkill(
                $teacher->id,
                $assignment->id,
                $curriculum->id,
                $versionOne->id,
                $lesson->id,
                $lessonRevision->id,
                $placement->id,
            );
            $lessonAuthority = fn (): string => $this->lessonAssessmentAuthoring->lockLessonLifecycle(
                $teacher->id,
                $assignment->id,
                $curriculum->id,
                $versionOne->id,
                $lesson->id,
                $lessonRevision->id,
            );
            $this->releaseLessonRevision->execute($lessonRevision->id, $lessonAuthority);
            $this->publishLesson->execute($lesson->id, $lessonRevision->id, $lessonAuthority);

            $assessment = $this->lessonAssessmentAuthoring->createAssessment(
                $teacher->id,
                $assignment->id,
                $curriculum->id,
                $versionOne->id,
                'single_choice',
                'Addition check',
            );
            $assessmentRevision = $this->lessonAssessmentAuthoring->createAssessmentRevision(
                $teacher->id,
                $assignment->id,
                $curriculum->id,
                $versionOne->id,
                $assessment->id,
                1,
                $topic->id,
                'easy',
                ['prompt' => 'What is 2 + 3?', 'options' => [4, 5, 6]],
                1,
                ['correct_option' => 1],
                1,
            );
            $this->lessonAssessmentAuthoring->createAssessmentRevisionSkill(
                $teacher->id,
                $assignment->id,
                $curriculum->id,
                $versionOne->id,
                $assessment->id,
                $assessmentRevision->id,
                $placement->id,
                'primary',
            );
            $assessmentAuthority = fn (): string => $this->lessonAssessmentAuthoring->lockAssessmentLifecycle(
                $teacher->id,
                $assignment->id,
                $curriculum->id,
                $versionOne->id,
                $assessment->id,
                $assessmentRevision->id,
            );
            $this->releaseAssessmentRevision->execute($assessmentRevision->id, $assessmentAuthority);
            $this->publishAssessment->execute($assessment->id, $assessmentRevision->id, $assessmentAuthority);

            $practice = $this->practiceExamAuthoring->createPractice(
                $teacher->id,
                $assignment->id,
                $curriculum->id,
                $versionOne->id,
                $lesson->id,
                'Addition practice',
                'A released-item local demonstration practice.',
            );
            $this->practiceExamAuthoring->addPracticeItems(
                $teacher->id,
                $assignment->id,
                $curriculum->id,
                $versionOne->id,
                $practice->id,
                [$assessmentRevision->id],
                1,
            );
            $this->practiceExamAuthoring->setPracticeStatus(
                $teacher->id,
                $assignment->id,
                $curriculum->id,
                $versionOne->id,
                $practice->id,
                'active',
            );

            $template = $this->practiceExamAuthoring->createTemplate(
                $teacher->id,
                $assignment->id,
                $curriculum->id,
                $versionOne->id,
                'Addition exam',
                'A local demonstration exam template.',
            );
            $templateVersion = $this->practiceExamAuthoring->createTemplateVersion(
                $teacher->id,
                $assignment->id,
                $curriculum->id,
                $versionOne->id,
                $template->id,
                1,
                'Published demonstration version',
                ['item_count' => 1],
                1,
            );
            $this->practiceExamAuthoring->publishTemplateVersion(
                $teacher->id,
                $assignment->id,
                $curriculum->id,
                $versionOne->id,
                $template->id,
                $templateVersion->id,
            );
            $generationItems = $this->practiceExamAuthoring->generationItems(
                $teacher->id,
                $assignment->id,
                $curriculum->id,
                $versionOne->id,
                $template->id,
                $templateVersion->id,
                [$assessmentRevision->id],
            );
            $generation = $this->buildExamGeneration->execute(
                $templateVersion->id,
                'local-demo-v1',
                'local-demo-seed-v1',
                $generationItems,
                fn (): string => $this->practiceExamAuthoring->lockGenerationAuthority(
                    $teacher->id,
                    $assignment->id,
                    $curriculum->id,
                    $versionOne->id,
                    $template->id,
                    $templateVersion->id,
                    [$assessmentRevision->id],
                ),
            );

            $this->publishCurriculumVersion->execute(
                $versionOne->id,
                fn (): string => $this->curriculumAuthoring->lockLifecycleAuthority(
                    $teacher->id,
                    $assignment->id,
                    $curriculum->id,
                ),
            );
            $versionTwo = $this->curriculumAuthoring->createVersion(
                $teacher->id,
                $assignment->id,
                $curriculum->id,
                2,
                'Teacher authoring draft',
            );

            $enrollment = $this->requestStudentEnrollment->execute(
                $student->id,
                $student->learnerProfile->id,
                $assignment->id,
                (string) Str::uuid(),
                'Local demonstration enrollment request',
            );
            $enrollment = $this->acceptStudentEnrollment->execute(
                $teacher->id,
                $enrollment->id,
                (string) Str::uuid(),
                'Local demonstration enrollment acceptance',
            );

            return [
                'admin_id' => $admin->id,
                'teacher_id' => $teacher->id,
                'student_id' => $student->id,
                'assignment_id' => $assignment->id,
                'enrollment_id' => $enrollment->id,
                'curriculum_id' => $curriculum->id,
                'published_version_id' => $versionOne->id,
                'draft_version_id' => $versionTwo->id,
                'skill_id' => $skill->id,
                'lesson_id' => $lesson->id,
                'assessment_id' => $assessment->id,
                'practice_id' => $practice->id,
                'exam_template_id' => $template->id,
                'exam_generation_id' => $generation->id,
            ];
        });
    }
}
