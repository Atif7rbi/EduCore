<?php

namespace Tests\Feature;

use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveUserAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_authenticated_user_reaches_learner_route(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
            'role' => 'student',
        ]);

        LearnerProfile::create([
            'user_id' => $user->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->get('/api/attempts/00000000-0000-4000-8000-000000000001');

        $response
            ->assertStatus(404)
            ->assertJson([
                'error' => [
                    'code' => 'not_found',
                ],
            ]);
    }

    public function test_disabled_authenticated_user_is_rejected_before_learner_route(): void
    {
        $user = User::factory()->create([
            'status' => 'disabled',
            'role' => 'student',
        ]);

        LearnerProfile::create([
            'user_id' => $user->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->get('/api/attempts/00000000-0000-4000-8000-000000000001');

        $response
            ->assertStatus(403)
            ->assertJson([
                'error' => [
                    'code' => 'account_disabled',
                    'message' => 'This account is disabled.',
                ],
            ]);
    }

    public function test_disabled_user_is_rejected_after_becoming_disabled(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
            'role' => 'student',
        ]);

        LearnerProfile::create([
            'user_id' => $user->id,
        ]);

        $this->actingAs($user);

        $user->forceFill([
            'status' => 'disabled',
        ])->save();

        $response = $this
            ->get('/api/attempts/00000000-0000-4000-8000-000000000001');

        $response
            ->assertStatus(403)
            ->assertJson([
                'error' => [
                    'code' => 'account_disabled',
                ],
            ]);
    }

    public function test_guest_is_still_rejected_by_auth_middleware(): void
    {
        $response = $this
            ->getJson('/api/attempts/00000000-0000-4000-8000-000000000001');

        $response->assertStatus(401);
    }
}
