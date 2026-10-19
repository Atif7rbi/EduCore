<?php

namespace Tests\Feature;

use App\Application\Taxonomy\CreateSkill;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateSkillTest extends TestCase
{
    use DatabaseTransactions;

    public function test_action_creates_canonical_global_skill_for_active_admin(): void
    {
        $admin = $this->user('admin', 'active');

        $skill = app(CreateSkill::class)->execute(
            actorUserId: $admin->id,
            name: 'Equivalent Fractions',
            description: 'Recognize equivalent fraction forms.',
        );

        $this->assertTrue(Str::isUuid($skill->id));
        $this->assertSame('Equivalent Fractions', $skill->name);
        $this->assertSame(
            'Recognize equivalent fraction forms.',
            $skill->description,
        );
        $this->assertDatabaseHas('skills', [
            'id' => $skill->id,
            'name' => 'Equivalent Fractions',
            'description' => 'Recognize equivalent fraction forms.',
        ]);
    }

    public function test_action_rejects_non_admin_actor_atomically(): void
    {
        $teacher = $this->user('teacher', 'active');

        try {
            app(CreateSkill::class)->execute(
                actorUserId: $teacher->id,
                name: 'Unauthorized Skill',
                description: null,
            );

            $this->fail('A non-admin actor created a Skill.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseMissing('skills', [
                'name' => 'Unauthorized Skill',
            ]);
        }
    }

    public function test_action_rejects_disabled_admin_atomically(): void
    {
        $admin = $this->user('admin', 'disabled');

        try {
            app(CreateSkill::class)->execute(
                actorUserId: $admin->id,
                name: 'Disabled Admin Skill',
                description: null,
            );

            $this->fail('A disabled admin created a Skill.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseMissing('skills', [
                'name' => 'Disabled Admin Skill',
            ]);
        }
    }

    public function test_action_rejects_unknown_actor_atomically(): void
    {
        try {
            app(CreateSkill::class)->execute(
                actorUserId: (string) Str::uuid(),
                name: 'Unknown Actor Skill',
                description: null,
            );

            $this->fail('An unknown actor created a Skill.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseMissing('skills', [
                'name' => 'Unknown Actor Skill',
            ]);
        }
    }

    public function test_duplicate_names_remain_distinct_global_skill_identities(): void
    {
        $admin = $this->user('admin', 'active');
        $action = app(CreateSkill::class);

        $first = $action->execute(
            actorUserId: $admin->id,
            name: 'Number Sense',
            description: 'First canonical identity.',
        );
        $second = $action->execute(
            actorUserId: $admin->id,
            name: 'Number Sense',
            description: 'Second canonical identity.',
        );

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(
            2,
            Skill::query()
                ->where('name', 'Number Sense')
                ->count(),
        );
    }

    public function test_endpoint_preserves_skill_validation_errors(): void
    {
        $this->actingAs($this->user('admin', 'active'));

        foreach ([
            [],
            ['name' => str_repeat('a', 256)],
            [
                'name' => 'Invalid Description',
                'description' => ['not-a-string'],
            ],
        ] as $payload) {
            $this->postJson('/api/admin/skills', $payload)
                ->assertStatus(422)
                ->assertJsonPath(
                    'error.code',
                    'validation_failed',
                );
        }

        $this->assertDatabaseCount('skills', 0);
    }

    public function test_endpoint_preserves_response_and_ignores_non_contract_identity_fields(): void
    {
        $this->actingAs($this->user('admin', 'active'));

        $clientSkillId = (string) Str::uuid();

        $response = $this->postJson('/api/admin/skills', [
            'id' => $clientSkillId,
            'subject_id' => (string) Str::uuid(),
            'name' => 'Global Geometry Skill',
        ]);

        $response
            ->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'description',
                    'created_at',
                    'updated_at',
                ],
            ])
            ->assertJsonPath('data.name', 'Global Geometry Skill')
            ->assertJsonPath('data.description', null)
            ->assertJsonMissingPath('data.subject_id');

        $this->assertNotSame(
            $clientSkillId,
            $response->json('data.id'),
        );
        $this->assertDatabaseHas('skills', [
            'id' => $response->json('data.id'),
            'name' => 'Global Geometry Skill',
            'description' => null,
        ]);
    }

    private function user(string $role, string $status): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => $status,
        ]);
    }
}
