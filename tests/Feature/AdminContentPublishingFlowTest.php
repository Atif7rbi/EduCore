<?php

namespace Tests\Feature;

use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminContentPublishingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_authored_content_survives_full_publish_flow(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin);

        [$curriculumVersionId, $topicId, $placementId] =
            $this->baseDraftCurriculum();

        /*
         * Lesson authoring.
         */
        $lesson = $this->postJson(
            "/api/admin/curriculum-versions/{$curriculumVersionId}/lessons",
            [
                'title' => 'Ratios',
                'description' => 'Ratios lesson',
                'display_order' => 0,
            ]
        )->assertCreated();

        $lessonId = $lesson->json('data.id');

        $lessonRevision = $this->postJson(
            "/api/admin/lessons/{$lessonId}/revisions",
            [
                'revision_number' => 1,
                'primary_topic_id' => $topicId,
                'content_payload' => [
                    'blocks' => [
                        [
                            'type' => 'text',
                            'text' => 'Ratio fundamentals',
                        ],
                    ],
                ],
                'content_schema_version' => 1,
            ]
        )->assertCreated();

        $lessonRevisionId =
            $lessonRevision->json('data.id');

        $this->postJson(
            "/api/admin/lesson-revisions/{$lessonRevisionId}/skills",
            [
                'skill_version_placement_id' =>
                    $placementId,
            ]
        )->assertCreated();

        $this->postJson(
            "/api/lesson-revisions/{$lessonRevisionId}/release"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $lessonRevisionId
            );

        $this->postJson(
            "/api/lessons/{$lessonId}/publish",
            [
                'published_revision_id' =>
                    $lessonRevisionId,
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'published'
            );

        /*
         * Assessment authoring.
         */
        $assessment = $this->postJson(
            "/api/admin/curriculum-versions/{$curriculumVersionId}/assessment-items",
            [
                'item_type' => 'multiple_choice',
                'internal_label' =>
                    'Ratios Question 1',
            ]
        )->assertCreated();

        $assessmentItemId =
            $assessment->json('data.id');

        $assessmentRevision =
            $this->postJson(
                "/api/admin/assessment-items/{$assessmentItemId}/revisions",
                [
                    'revision_number' => 1,
                    'primary_topic_id' =>
                        $topicId,
                    'difficulty' => 'easy',
                    'content_payload' => [
                        'stem' =>
                            'What is 2:4 simplified?',
                        'options' => [
                            '1:2',
                            '2:3',
                            '3:4',
                            '4:5',
                        ],
                    ],
                    'content_schema_version' => 1,
                    'scoring_payload' => [
                        'correct_option' => 0,
                    ],
                    'scoring_schema_version' => 1,
                ]
            )->assertCreated();

        $assessmentRevisionId =
            $assessmentRevision->json('data.id');

        $this->postJson(
            "/api/admin/assessment-item-revisions/{$assessmentRevisionId}/skills",
            [
                'skill_version_placement_id' =>
                    $placementId,
                'role' => 'primary',
            ]
        )->assertCreated();

        $this->postJson(
            "/api/assessment-item-revisions/{$assessmentRevisionId}/release"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $assessmentRevisionId
            );

        $this->postJson(
            "/api/assessment-items/{$assessmentItemId}/publish",
            [
                'published_revision_id' =>
                    $assessmentRevisionId,
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'published'
            );

        /*
         * Practice activity.
         */
        $practice = $this->postJson(
            "/api/admin/curriculum-versions/{$curriculumVersionId}/practice-activities",
            [
                'lesson_id' => $lessonId,
                'name' => 'Ratios Practice',
                'description' =>
                    'Ratios practice activity',
            ]
        )
            ->assertCreated()
            ->assertJsonPath(
                'data.status',
                'archived'
            );

        $practiceActivityId =
            $practice->json('data.id');

        $this->postJson(
            "/api/admin/practice-activities/{$practiceActivityId}/items",
            [
                'assessment_item_revision_id' =>
                    $assessmentRevisionId,
                'display_order' => 0,
            ]
        )->assertCreated();

        $this->postJson(
            "/api/admin/practice-activities/{$practiceActivityId}/activate"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'active'
            )
            ->assertJsonPath(
                'data.items_count',
                1
            );

        /*
         * Exam template.
         */
        $template = $this->postJson(
            "/api/admin/curriculum-versions/{$curriculumVersionId}/exam-templates",
            [
                'name' => 'Ratios Mock',
                'description' =>
                    'Ratios mock exam',
            ]
        )->assertCreated();

        $templateId =
            $template->json('data.id');

        $templateVersion = $this->postJson(
            "/api/admin/exam-templates/{$templateId}/versions",
            [
                'version_number' => 1,
                'label' => 'Ratios Mock v1',
                'rules_payload' => [
                    'question_count' => 1,
                    'difficulty' => [
                        'easy' => 1,
                    ],
                ],
                'rules_schema_version' => 1,
            ]
        )->assertCreated();

        $templateVersionId =
            $templateVersion->json('data.id');

        $this->postJson(
            "/api/admin/exam-template-versions/{$templateVersionId}/publish"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.version.status',
                'published'
            )
            ->assertJsonPath(
                'data.template.published_version_id',
                $templateVersionId
            );

        /*
         * Curriculum publish is the aggregate boundary.
         */
        $this->postJson(
            "/api/curriculum-versions/{$curriculumVersionId}/publish"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'published'
            );

        /*
         * Authoring must now be frozen.
         */
        $this->postJson(
            "/api/admin/curriculum-versions/{$curriculumVersionId}/lessons",
            [
                'title' => 'Forbidden after publish',
                'description' => null,
                'display_order' => 99,
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'curriculum_version_not_draft'
            );

        /*
         * Published template remains usable for deterministic
         * ExamGeneration construction.
         */
        $generation = $this->postJson(
            "/api/exam-template-versions/{$templateVersionId}/generations",
            [
                'generator_version' =>
                    'generator-v1',
                'seed' =>
                    'a6-final-flow-seed',
                'items' => [
                    [
                        'assessment_item_revision_id' =>
                            $assessmentRevisionId,
                        'assessment_item_id' =>
                            $assessmentItemId,
                    ],
                ],
            ]
        );

        $generation
            ->assertCreated()
            ->assertJsonPath(
                'data.exam_template_version_id',
                $templateVersionId
            )
            ->assertJsonPath(
                'data.curriculum_version_id',
                $curriculumVersionId
            )
            ->assertJsonPath(
                'data.items.0.assessment_item_revision_id',
                $assessmentRevisionId
            );

        $this->assertNotNull(
            $generation->json(
                'data.generated_at'
            )
        );

        /*
         * Switch identity and prove learner visibility comes
         * only after curriculum publication.
         */
        $learnerUser =
            User::factory()->create([
                'role' => 'student',
                'status' => 'active',
            ]);

        LearnerProfile::query()->create([
            'user_id' => $learnerUser->id,
        ]);

        $this->actingAs($learnerUser);

        $this->getJson(
            "/api/curriculum-versions/{$curriculumVersionId}"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'published'
            );

        $this->getJson(
            "/api/curriculum-versions/{$curriculumVersionId}/lessons"
        )
            ->assertOk()
            ->assertJsonFragment([
                'id' => $lessonId,
                'title' => 'Ratios',
            ]);

        $this->getJson(
            "/api/lessons/{$lessonId}"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $lessonId
            )
            ->assertJsonPath(
                'data.practice_activities.0.id',
                $practiceActivityId
            );

        $this->getJson(
            "/api/practice-activities/{$practiceActivityId}"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $practiceActivityId
            )
            ->assertJsonPath(
                'data.status',
                'active'
            );

        /*
         * Historical pins must still point to the exact
         * released revisions that were authored.
         */
        $this->assertDatabaseHas(
            'lessons',
            [
                'id' => $lessonId,
                'published_revision_id' =>
                    $lessonRevisionId,
                'status' => 'published',
            ]
        );

        $this->assertDatabaseHas(
            'assessment_items',
            [
                'id' => $assessmentItemId,
                'published_revision_id' =>
                    $assessmentRevisionId,
                'status' => 'published',
            ]
        );

        $this->assertDatabaseHas(
            'exam_templates',
            [
                'id' => $templateId,
                'published_version_id' =>
                    $templateVersionId,
            ]
        );
    }

    private function baseDraftCurriculum(): array
    {
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $curriculumVersionId =
            (string) Str::uuid();
        $topicId = (string) Str::uuid();
        $skillId = (string) Str::uuid();
        $placementId = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' =>
                'A6 Final Subject '.Str::random(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' =>
                'A6 Final Curriculum '.Str::random(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table(
            'curriculum_versions'
        )->insert([
            'id' => $curriculumVersionId,
            'curriculum_id' =>
                $curriculumId,
            'version_number' => 1,
            'label' => 'A6 Final v1',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('topics')->insert([
            'id' => $topicId,
            'curriculum_version_id' =>
                $curriculumVersionId,
            'name' => 'Ratios',
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('skills')->insert([
            'id' => $skillId,
            'name' =>
                'Simplify ratios '.Str::random(8),
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table(
            'skill_version_placements'
        )->insert([
            'id' => $placementId,
            'skill_id' => $skillId,
            'curriculum_version_id' =>
                $curriculumVersionId,
            'created_at' => now(),
        ]);

        return [
            $curriculumVersionId,
            $topicId,
            $placementId,
        ];
    }
}
