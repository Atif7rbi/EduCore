<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminCurriculumReadinessApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_curriculum_readiness(): void
    {
        $this->getJson(
            '/api/admin/curriculum-versions/'.
            Str::uuid().
            '/readiness'
        )->assertUnauthorized();
    }

    public function test_empty_draft_curriculum_reports_blockers(): void
    {
        $this->actingAs($this->admin());

        [$versionId] =
            $this->baseDraftCurriculum(
                withTaxonomy: false
            );

        $response = $this->getJson(
            "/api/admin/curriculum-versions/{$versionId}/readiness"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.curriculum_version.id',
                $versionId
            )
            ->assertJsonPath(
                'data.curriculum_version.status',
                'draft'
            )
            ->assertJsonPath(
                'data.ready_to_publish',
                false
            )
            ->assertJsonPath(
                'data.counts.topics',
                0
            )
            ->assertJsonPath(
                'data.counts.skill_placements',
                0
            )
            ->assertJsonPath(
                'data.counts.published_lessons',
                0
            )
            ->assertJsonPath(
                'data.counts.published_assessment_items',
                0
            )
            ->assertJsonPath(
                'data.counts.learner_usable_practice_activities',
                0
            )
            ->assertJsonPath(
                'data.counts.usable_exam_templates',
                0
            )
            ->assertJsonCount(
                7,
                'data.checks'
            )
            ->assertJsonCount(
                6,
                'data.blockers'
            );

        $response->assertJsonFragment([
            'code' => 'has_published_lesson',
            'passed' => false,
            'value' => 0,
        ]);

        $response->assertJsonFragment([
            'code' =>
                'has_usable_exam_template',
            'passed' => false,
            'value' => 0,
        ]);
    }

    public function test_complete_curriculum_is_ready_with_non_blocking_warning(): void
    {
        $this->actingAs($this->admin());

        [
            $versionId,
            $topicId,
            $placementId,
        ] = $this->baseDraftCurriculum();

        $lesson = $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/lessons",
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
                            'text' =>
                                'Ratio fundamentals',
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
        )->assertOk();

        $this->postJson(
            "/api/lessons/{$lessonId}/publish",
            [
                'published_revision_id' =>
                    $lessonRevisionId,
            ]
        )->assertOk();

        $assessment = $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/assessment-items",
            [
                'item_type' =>
                    'multiple_choice',
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
        )->assertOk();

        $this->postJson(
            "/api/assessment-items/{$assessmentItemId}/publish",
            [
                'published_revision_id' =>
                    $assessmentRevisionId,
            ]
        )->assertOk();

        $practice = $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/practice-activities",
            [
                'lesson_id' => $lessonId,
                'name' => 'Ratios Practice',
                'description' =>
                    'Ratios practice activity',
            ]
        )->assertCreated();

        $practiceId =
            $practice->json('data.id');

        $this->postJson(
            "/api/admin/practice-activities/{$practiceId}/items",
            [
                'assessment_item_revision_id' =>
                    $assessmentRevisionId,
                'display_order' => 0,
            ]
        )->assertCreated();

        $this->postJson(
            "/api/admin/practice-activities/{$practiceId}/activate"
        )->assertOk();

        $template = $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/exam-templates",
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
        )->assertOk();

        /*
         * Deliberately retain one excluded draft lesson.
         * It must be a warning, not a publishing blocker.
         */
        $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/lessons",
            [
                'title' =>
                    'Future optional lesson',
                'description' => null,
                'display_order' => 99,
            ]
        )->assertCreated();

        $response = $this->getJson(
            "/api/admin/curriculum-versions/{$versionId}/readiness"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.ready_to_publish',
                true
            )
            ->assertJsonPath(
                'data.counts.published_lessons',
                1
            )
            ->assertJsonPath(
                'data.counts.draft_lessons',
                1
            )
            ->assertJsonPath(
                'data.counts.published_assessment_items',
                1
            )
            ->assertJsonPath(
                'data.counts.learner_usable_practice_activities',
                1
            )
            ->assertJsonPath(
                'data.counts.usable_exam_templates',
                1
            )
            ->assertJsonCount(
                0,
                'data.blockers'
            );

        $response->assertJsonFragment([
            'code' => 'draft_lessons',
            'value' => 1,
        ]);
    }

    public function test_active_practice_linked_to_draft_lesson_is_not_learner_usable(): void
    {
        $this->actingAs($this->admin());

        [
            $versionId,
            $topicId,
            $placementId,
        ] = $this->baseDraftCurriculum();

        $lesson = $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/lessons",
            [
                'title' => 'Draft host lesson',
                'description' => null,
                'display_order' => 0,
            ]
        )->assertCreated();

        $lessonId = $lesson->json('data.id');

        $assessment = $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/assessment-items",
            [
                'item_type' => 'multiple_choice',
                'internal_label' => 'Practice source',
            ]
        )->assertCreated();

        $assessmentItemId =
            $assessment->json('data.id');

        $revision = $this->postJson(
            "/api/admin/assessment-items/{$assessmentItemId}/revisions",
            [
                'revision_number' => 1,
                'primary_topic_id' => $topicId,
                'difficulty' => 'easy',
                'content_payload' => [
                    'stem' => 'Question?',
                    'options' => [
                        'A',
                        'B',
                        'C',
                        'D',
                    ],
                ],
                'content_schema_version' => 1,
                'scoring_payload' => [
                    'correct_option' => 0,
                ],
                'scoring_schema_version' => 1,
            ]
        )->assertCreated();

        $revisionId =
            $revision->json('data.id');

        $this->postJson(
            "/api/admin/assessment-item-revisions/{$revisionId}/skills",
            [
                'skill_version_placement_id' =>
                    $placementId,
                'role' => 'primary',
            ]
        )->assertCreated();

        $this->postJson(
            "/api/assessment-item-revisions/{$revisionId}/release"
        )->assertOk();

        $practice = $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/practice-activities",
            [
                'lesson_id' => $lessonId,
                'name' => 'Hidden practice',
                'description' => null,
            ]
        )->assertCreated();

        $practiceId =
            $practice->json('data.id');

        $this->postJson(
            "/api/admin/practice-activities/{$practiceId}/items",
            [
                'assessment_item_revision_id' =>
                    $revisionId,
                'display_order' => 0,
            ]
        )->assertCreated();

        $this->postJson(
            "/api/admin/practice-activities/{$practiceId}/activate"
        )->assertOk();

        $response = $this->getJson(
            "/api/admin/curriculum-versions/{$versionId}/readiness"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.counts.active_practice_activities',
                1
            )
            ->assertJsonPath(
                'data.counts.learner_usable_practice_activities',
                0
            )
            ->assertJsonPath(
                'data.counts.active_practice_hidden_by_lesson',
                1
            );

        $response->assertJsonFragment([
            'code' =>
                'has_learner_usable_practice',
            'passed' => false,
            'value' => 0,
        ]);

        $response->assertJsonFragment([
            'code' =>
                'active_practice_hidden_by_lesson',
            'value' => 1,
        ]);
    }

    public function test_active_exam_template_without_published_version_is_not_usable(): void
    {
        $this->actingAs($this->admin());

        [$versionId] =
            $this->baseDraftCurriculum();

        $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/exam-templates",
            [
                'name' => 'Unversioned mock',
                'description' => null,
            ]
        )->assertCreated();

        $response = $this->getJson(
            "/api/admin/curriculum-versions/{$versionId}/readiness"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.counts.active_exam_templates',
                1
            )
            ->assertJsonPath(
                'data.counts.usable_exam_templates',
                0
            )
            ->assertJsonPath(
                'data.counts.active_exam_templates_without_published_version',
                1
            );

        $response->assertJsonFragment([
            'code' =>
                'has_usable_exam_template',
            'passed' => false,
            'value' => 0,
        ]);

        $response->assertJsonFragment([
            'code' =>
                'active_exam_templates_without_published_version',
            'value' => 1,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function baseDraftCurriculum(
        bool $withTaxonomy = true,
    ): array {
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' =>
                'Readiness Subject '.
                Str::random(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' =>
                'Readiness Curriculum '.
                Str::random(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curriculum_versions')->insert([
            'id' => $versionId,
            'curriculum_id' => $curriculumId,
            'version_number' => 1,
            'label' => 'Readiness v1',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! $withTaxonomy) {
            return [$versionId];
        }

        $topicId = (string) Str::uuid();
        $skillId = (string) Str::uuid();
        $placementId = (string) Str::uuid();

        DB::table('topics')->insert([
            'id' => $topicId,
            'curriculum_version_id' =>
                $versionId,
            'name' => 'Ratios',
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('skills')->insert([
            'id' => $skillId,
            'name' =>
                'Readiness Skill '.
                Str::random(8),
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
                $versionId,
            'created_at' => now(),
        ]);

        return [
            $versionId,
            $topicId,
            $placementId,
        ];
    }
}
