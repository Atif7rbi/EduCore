<?php

namespace Tests\Feature;

use App\Application\Analytics\CreateEvidenceScope;
use App\Application\Analytics\RetireEvidenceScope;
use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvidenceScopeReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_learner_can_list_active_and_retired_evidence_scopes(): void
    {
        $this->authenticateLearner();

        $active = app(
            CreateEvidenceScope::class
        )->execute(
            'Current Evidence',
            'Current analytical identity',
            ['opaque' => 'current'],
            2,
        );

        $retired = app(
            CreateEvidenceScope::class
        )->execute(
            'Historical Evidence',
            'Historical analytical identity',
            ['opaque' => 'historical'],
            1,
        );

        app(
            RetireEvidenceScope::class
        )->execute(
            $retired->id
        );

        $this->getJson(
            '/api/analytics/evidence-scopes'
        )
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'id' => $active->id,
                        'label' => 'Current Evidence',
                        'description' => 'Current analytical identity',
                        'status' => 'active',
                        'definition_schema_version' => 2,
                    ],
                    [
                        'id' => $retired->id,
                        'label' => 'Historical Evidence',
                        'description' => 'Historical analytical identity',
                        'status' => 'retired',
                        'definition_schema_version' => 1,
                    ],
                ],
            ]);
    }

    public function test_scope_inventory_is_deterministic_with_active_first(): void
    {
        $this->authenticateLearner();

        $zeta = app(
            CreateEvidenceScope::class
        )->execute(
            'Zeta',
            null,
            ['opaque' => 'zeta'],
            1,
        );

        $alpha = app(
            CreateEvidenceScope::class
        )->execute(
            'Alpha',
            null,
            ['opaque' => 'alpha'],
            1,
        );

        $retired = app(
            CreateEvidenceScope::class
        )->execute(
            'A Retired Scope',
            null,
            ['opaque' => 'retired'],
            1,
        );

        app(
            RetireEvidenceScope::class
        )->execute(
            $retired->id
        );

        $this->getJson(
            '/api/analytics/evidence-scopes'
        )
            ->assertOk()
            ->assertJsonPath(
                'data.0.id',
                $alpha->id
            )
            ->assertJsonPath(
                'data.1.id',
                $zeta->id
            )
            ->assertJsonPath(
                'data.2.id',
                $retired->id
            );
    }

    public function test_scope_inventory_does_not_expose_definition_payload(): void
    {
        $this->authenticateLearner();

        app(
            CreateEvidenceScope::class
        )->execute(
            'Opaque Scope',
            null,
            [
                'private_semantics' => 'must-not-be-exposed',
            ],
            1,
        );

        $this->getJson(
            '/api/analytics/evidence-scopes'
        )
            ->assertOk()
            ->assertJsonMissingPath(
                'data.0.definition_payload'
            )
            ->assertJsonMissing([
                'private_semantics' => 'must-not-be-exposed',
            ]);
    }

    public function test_scope_inventory_can_be_empty(): void
    {
        $this->authenticateLearner();

        $this->getJson(
            '/api/analytics/evidence-scopes'
        )
            ->assertOk()
            ->assertExactJson([
                'data' => [],
            ]);
    }

    public function test_scope_inventory_requires_authentication(): void
    {
        $this->getJson(
            '/api/analytics/evidence-scopes'
        )->assertStatus(401);
    }

    public function test_scope_inventory_requires_learner_profile(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->actingAs($user);

        $this->getJson(
            '/api/analytics/evidence-scopes'
        )->assertStatus(403);
    }

    private function authenticateLearner(): LearnerProfile
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $learner = LearnerProfile::query()
            ->create([
                'user_id' => $user->id,
            ]);

        $this->actingAs($user);

        return $learner;
    }
}
