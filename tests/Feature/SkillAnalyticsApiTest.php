<?php

namespace Tests\Feature;

use App\Application\Analytics\CreateEvidenceScope;
use App\Models\LearnerProfile;
use App\Models\MaterializedSkillPerformance;
use App\Models\Skill;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkillAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_materialization_returns_explicit_lifetime_scope_with_no_skills(): void
    {
        [$learner, $scope] =
            $this->authenticateLearner();

        $this->getJson(
            '/api/analytics/skills'
            .'?evidence_scope_id='
            .$scope->id
        )
            ->assertOk()
            ->assertJsonPath(
                'data.evidence_scope.id',
                $scope->id
            )
            ->assertJsonPath(
                'data.evidence_scope.status',
                'active'
            )
            ->assertJsonPath(
                'data.temporal_boundary',
                'lifetime'
            )
            ->assertJsonPath(
                'data.skills',
                []
            )
            ->assertJsonMissingPath(
                'data.global_score'
            )
            ->assertJsonMissingPath(
                'data.mastery'
            );

        $this->assertNotNull($learner);
    }

    public function test_skill_analytics_exposes_counts_and_defined_accuracy(): void
    {
        [$learner, $scope] =
            $this->authenticateLearner();

        $skill = Skill::query()->create([
            'name' => 'Ratios',
            'description' =>
                'Ratio reasoning',
        ]);

        MaterializedSkillPerformance::query()
            ->create([
                'learner_profile_id' =>
                    $learner->id,
                'skill_id' =>
                    $skill->id,
                'evidence_scope_id' =>
                    $scope->id,
                'single_primary_correct_count' =>
                    3,
                'single_primary_answered_count' =>
                    4,
                'supporting_positive_count' =>
                    2,
                'supporting_exposure_count' =>
                    5,
                'last_rebuilt_at' =>
                    CarbonImmutable::now(
                        'UTC'
                    ),
            ]);

        $this->getJson(
            '/api/analytics/skills'
            .'?evidence_scope_id='
            .$scope->id
        )
            ->assertOk()
            ->assertJsonPath(
                'data.skills.0.skill.id',
                $skill->id
            )
            ->assertJsonPath(
                'data.skills.0.skill.name',
                'Ratios'
            )
            ->assertJsonPath(
                'data.skills.0.single_primary.correct_count',
                3
            )
            ->assertJsonPath(
                'data.skills.0.single_primary.answered_count',
                4
            )
            ->assertJsonPath(
                'data.skills.0.single_primary.accuracy',
                0.75
            )
            ->assertJsonPath(
                'data.skills.0.supporting.positive_count',
                2
            )
            ->assertJsonPath(
                'data.skills.0.supporting.exposure_count',
                5
            )
            ->assertJsonMissingPath(
                'data.skills.0.supporting.accuracy'
            )
            ->assertJsonMissingPath(
                'data.skills.0.mastery'
            );
    }

    public function test_zero_single_primary_denominator_returns_null_accuracy_not_zero_percent(): void
    {
        [$learner, $scope] =
            $this->authenticateLearner();

        $skill = Skill::query()->create([
            'name' =>
                'Supporting Only Skill',
            'description' => null,
        ]);

        MaterializedSkillPerformance::query()
            ->create([
                'learner_profile_id' =>
                    $learner->id,
                'skill_id' =>
                    $skill->id,
                'evidence_scope_id' =>
                    $scope->id,
                'single_primary_correct_count' =>
                    0,
                'single_primary_answered_count' =>
                    0,
                'supporting_positive_count' =>
                    1,
                'supporting_exposure_count' =>
                    2,
                'last_rebuilt_at' =>
                    CarbonImmutable::now(
                        'UTC'
                    ),
            ]);

        $this->getJson(
            '/api/analytics/skills'
            .'?evidence_scope_id='
            .$scope->id
        )
            ->assertOk()
            ->assertJsonPath(
                'data.skills.0.single_primary.answered_count',
                0
            )
            ->assertJsonPath(
                'data.skills.0.single_primary.accuracy',
                null
            );
    }

    public function test_skill_analytics_is_scoped_to_authenticated_learner(): void
    {
        [$learner, $scope] =
            $this->authenticateLearner();

        $otherLearner =
            $this->learner();

        $ownSkill = Skill::query()->create([
            'name' => 'Own Skill',
            'description' => null,
        ]);

        $otherSkill = Skill::query()->create([
            'name' => 'Other Skill',
            'description' => null,
        ]);

        $this->materialize(
            $learner->id,
            $ownSkill->id,
            $scope->id,
        );

        $this->materialize(
            $otherLearner->id,
            $otherSkill->id,
            $scope->id,
        );

        $response = $this->getJson(
            '/api/analytics/skills'
            .'?evidence_scope_id='
            .$scope->id
        );

        $response
            ->assertOk()
            ->assertJsonFragment([
                'id' => $ownSkill->id,
                'name' => 'Own Skill',
            ])
            ->assertJsonMissing([
                'id' => $otherSkill->id,
                'name' => 'Other Skill',
            ]);
    }

    public function test_skill_analytics_is_scoped_to_explicit_evidence_scope(): void
    {
        [$learner, $scope] =
            $this->authenticateLearner();

        $otherScope = app(
            CreateEvidenceScope::class
        )->execute(
            'Other Scope',
            null,
            ['opaque' => 'other'],
            1,
        );

        $ownScopeSkill =
            Skill::query()->create([
                'name' => 'Selected Scope Skill',
                'description' => null,
            ]);

        $otherScopeSkill =
            Skill::query()->create([
                'name' => 'Other Scope Skill',
                'description' => null,
            ]);

        $this->materialize(
            $learner->id,
            $ownScopeSkill->id,
            $scope->id,
        );

        $this->materialize(
            $learner->id,
            $otherScopeSkill->id,
            $otherScope->id,
        );

        $this->getJson(
            '/api/analytics/skills'
            .'?evidence_scope_id='
            .$scope->id
        )
            ->assertOk()
            ->assertJsonFragment([
                'id' => $ownScopeSkill->id,
            ])
            ->assertJsonMissing([
                'id' => $otherScopeSkill->id,
            ]);
    }

    public function test_retired_scope_remains_explicitly_readable_as_historical_analytical_identity(): void
    {
        [$learner, $scope] =
            $this->authenticateLearner();

        $skill = Skill::query()->create([
            'name' => 'Historical Scope Skill',
            'description' => null,
        ]);

        $this->materialize(
            $learner->id,
            $skill->id,
            $scope->id,
        );

        app(
            \App\Application\Analytics\RetireEvidenceScope::class
        )->execute(
            $scope->id
        );

        $this->getJson(
            '/api/analytics/skills'
            .'?evidence_scope_id='
            .$scope->id
        )
            ->assertOk()
            ->assertJsonPath(
                'data.evidence_scope.status',
                'retired'
            )
            ->assertJsonPath(
                'data.skills.0.skill.id',
                $skill->id
            );
    }

    public function test_skill_analytics_requires_evidence_scope(): void
    {
        $this->authenticateLearner();

        $this->getJson(
            '/api/analytics/skills'
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'validation_failed'
            )
            ->assertJsonPath(
                'error.message',
                'The submitted data is invalid.'
            )
            ->assertJsonStructure([
                'error' => [
                    'details' => [
                        'evidence_scope_id',
                    ],
                ],
            ]);
    }

    public function test_skill_analytics_requires_existing_evidence_scope(): void
    {
        $this->authenticateLearner();

        $this->getJson(
            '/api/analytics/skills'
            .'?evidence_scope_id='
            .'00000000-0000-4000-8000-000000000001'
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'validation_failed'
            )
            ->assertJsonPath(
                'error.message',
                'The submitted data is invalid.'
            )
            ->assertJsonStructure([
                'error' => [
                    'details' => [
                        'evidence_scope_id',
                    ],
                ],
            ]);
    }

    public function test_skill_analytics_requires_authentication(): void
    {
        $this->getJson(
            '/api/analytics/skills'
            .'?evidence_scope_id='
            .'00000000-0000-4000-8000-000000000001'
        )->assertStatus(401);
    }

    private function authenticateLearner(): array
    {
        $learner = $this->learner();

        $this->actingAs(
            $learner->user
        );

        $scope = app(
            CreateEvidenceScope::class
        )->execute(
            'Learner Analytics Scope',
            null,
            ['opaque' => true],
            1,
        );

        return [
            $learner,
            $scope,
        ];
    }

    private function learner(): LearnerProfile
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        return LearnerProfile::query()
            ->create([
                'user_id' => $user->id,
            ])
            ->load('user');
    }

    private function materialize(
        string $learnerProfileId,
        string $skillId,
        string $scopeId,
    ): void {
        MaterializedSkillPerformance::query()
            ->create([
                'learner_profile_id' =>
                    $learnerProfileId,
                'skill_id' => $skillId,
                'evidence_scope_id' =>
                    $scopeId,
                'single_primary_correct_count' =>
                    1,
                'single_primary_answered_count' =>
                    1,
                'supporting_positive_count' =>
                    0,
                'supporting_exposure_count' =>
                    0,
                'last_rebuilt_at' =>
                    CarbonImmutable::now(
                        'UTC'
                    ),
            ]);
    }
}
