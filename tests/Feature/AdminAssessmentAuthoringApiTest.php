<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAssessmentAuthoringApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_list_and_update_draft_item(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version('draft');

        $response = $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/assessment-items",
            [
                'item_type' => 'multiple_choice',
                'internal_label' => 'Ratio Q1',
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.curriculum_version_id',
                $versionId
            )
            ->assertJsonPath(
                'data.item_type',
                'multiple_choice'
            )
            ->assertJsonPath(
                'data.status',
                'draft'
            );

        $itemId = $response->json('data.id');

        $this->getJson(
            "/api/admin/curriculum-versions/{$versionId}/assessment-items"
        )
            ->assertOk()
            ->assertJsonFragment([
                'id' => $itemId,
                'internal_label' => 'Ratio Q1',
            ]);

        $this->putJson(
            "/api/admin/assessment-items/{$itemId}",
            [
                'item_type' => 'multiple_choice',
                'internal_label' => 'Ratio Q1 Updated',
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.internal_label',
                'Ratio Q1 Updated'
            );
    }

    public function test_item_mutation_is_frozen_outside_draft_curriculum_version(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version('draft');
        $itemId = $this->item($versionId);

        DB::table('curriculum_versions')
            ->where('id', $versionId)
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/assessment-items",
            [
                'item_type' => 'multiple_choice',
                'internal_label' => 'Forbidden',
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'curriculum_version_not_draft'
            );

        $this->putJson(
            "/api/admin/assessment-items/{$itemId}",
            [
                'item_type' => 'multiple_choice',
                'internal_label' => 'Changed',
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'curriculum_version_not_draft'
            );
    }

    public function test_admin_can_create_and_list_unreleased_revision(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version('draft');
        $itemId = $this->item($versionId);
        $topicId = $this->topic($versionId);

        $response = $this->postJson(
            "/api/admin/assessment-items/{$itemId}/revisions",
            [
                'revision_number' => 1,
                'primary_topic_id' => $topicId,
                'difficulty' => 'medium',
                'content_payload' => [
                    'prompt' => 'What is 2:4 simplified?',
                    'choices' => [
                        '1:2',
                        '2:3',
                        '3:4',
                        '4:5',
                    ],
                ],
                'content_schema_version' => 1,
                'scoring_payload' => [
                    'correct_choice' => 0,
                ],
                'scoring_schema_version' => 1,
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.assessment_item_id',
                $itemId
            )
            ->assertJsonPath(
                'data.revision_number',
                1
            )
            ->assertJsonPath(
                'data.difficulty',
                'medium'
            )
            ->assertJsonPath(
                'data.released_at',
                null
            );

        $revisionId =
            $response->json('data.id');

        $this->getJson(
            "/api/admin/assessment-items/{$itemId}/revisions"
        )
            ->assertOk()
            ->assertJsonFragment([
                'id' => $revisionId,
                'revision_number' => 1,
                'difficulty' => 'medium',
            ]);
    }

    public function test_revision_number_is_unique_per_item(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version('draft');
        $itemId = $this->item($versionId);

        $payload = [
            'revision_number' => 1,
            'primary_topic_id' => null,
            'difficulty' => 'easy',
            'content_payload' => [
                'prompt' => 'Question',
            ],
            'content_schema_version' => 1,
            'scoring_payload' => [
                'correct' => true,
            ],
            'scoring_schema_version' => 1,
        ];

        $this->postJson(
            "/api/admin/assessment-items/{$itemId}/revisions",
            $payload
        )->assertCreated();

        $this->postJson(
            "/api/admin/assessment-items/{$itemId}/revisions",
            $payload
        )->assertStatus(409);
    }

    public function test_primary_topic_from_different_version_is_rejected(): void
    {
        $this->actingAs($this->admin());

        $versionOne = $this->version('draft');
        $versionTwo = $this->version('draft');

        $itemId = $this->item($versionOne);
        $wrongTopicId = $this->topic($versionTwo);

        $this->postJson(
            "/api/admin/assessment-items/{$itemId}/revisions",
            [
                'revision_number' => 1,
                'primary_topic_id' => $wrongTopicId,
                'difficulty' => 'hard',
                'content_payload' => [
                    'prompt' => 'Cross-version',
                ],
                'content_schema_version' => 1,
                'scoring_payload' => [
                    'correct' => true,
                ],
                'scoring_schema_version' => 1,
            ]
        )->assertStatus(409);
    }

    public function test_revision_validation_rejects_invalid_difficulty(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version('draft');
        $itemId = $this->item($versionId);

        $this->postJson(
            "/api/admin/assessment-items/{$itemId}/revisions",
            [
                'revision_number' => 1,
                'primary_topic_id' => null,
                'difficulty' => 'impossible',
                'content_payload' => [
                    'prompt' => 'Invalid',
                ],
                'content_schema_version' => 1,
                'scoring_payload' => [
                    'correct' => true,
                ],
                'scoring_schema_version' => 1,
            ]
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'validation_failed'
            );
    }

    public function test_revision_authoring_is_frozen_after_curriculum_publish(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version('draft');
        $itemId = $this->item($versionId);

        DB::table('curriculum_versions')
            ->where('id', $versionId)
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        $this->postJson(
            "/api/admin/assessment-items/{$itemId}/revisions",
            [
                'revision_number' => 1,
                'primary_topic_id' => null,
                'difficulty' => 'easy',
                'content_payload' => [
                    'prompt' => 'Forbidden',
                ],
                'content_schema_version' => 1,
                'scoring_payload' => [
                    'correct' => true,
                ],
                'scoring_schema_version' => 1,
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'curriculum_version_not_draft'
            );
    }

    public function test_assessment_authoring_rejects_guest(): void
    {
        $id = (string) Str::uuid();

        $this->getJson(
            "/api/admin/curriculum-versions/{$id}/assessment-items"
        )
            ->assertStatus(401)
            ->assertJsonPath(
                'error.code',
                'unauthenticated'
            );
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function version(
        string $status,
    ): string {
        $subjectId = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' => 'Subject '.Str::random(12),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $curriculumId = (string) Str::uuid();

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => 'Curriculum '.Str::random(12),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $versionId = (string) Str::uuid();

        DB::table('curriculum_versions')->insert([
            'id' => $versionId,
            'curriculum_id' => $curriculumId,
            'version_number' => 1,
            'label' => 'Version '.Str::random(8),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $versionId;
    }

    private function item(
        string $versionId,
    ): string {
        $id = (string) Str::uuid();

        DB::table('assessment_items')->insert([
            'id' => $id,
            'curriculum_version_id' =>
                $versionId,
            'item_type' => 'multiple_choice',
            'internal_label' =>
                'Item '.Str::random(12),
            'status' => 'draft',
            'published_revision_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function topic(
        string $versionId,
    ): string {
        $id = (string) Str::uuid();

        DB::table('topics')->insert([
            'id' => $id,
            'curriculum_version_id' =>
                $versionId,
            'name' => 'Topic '.Str::random(12),
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
