<?php

namespace Tests\Feature;

use Tests\TestCase;

class FrontendEntryRoutesTest extends TestCase
{
    public function test_root_redirects_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }

    public function test_login_serves_frontend_application(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
    }

    public function test_guest_auth_me_redirects_to_login(): void
    {
        $response = $this->get('/auth/me');

        $response->assertRedirect('/login');
    }

    public function test_app_entry_serves_frontend_application(): void
    {
        $response = $this->get('/app');

        $response->assertOk();
    }

    public function test_admin_entry_serves_frontend_application(): void
    {
        $response = $this->get('/admin');

        $response->assertOk();
    }

    public function test_teacher_entry_serves_frontend_application(): void
    {
        $response = $this->get('/teacher');

        $response
            ->assertOk()
            ->assertViewIs('app');
    }

    public function test_teacher_workspace_entry_serves_frontend_application(): void
    {
        $response = $this->get('/teacher/workspace');

        $response
            ->assertOk()
            ->assertViewIs('app');
    }

    public function test_teacher_scoped_workspace_deep_link_serves_frontend_application(): void
    {
        $response = $this->get(
            '/teacher/workspace/assignment-a/curricula/curriculum-a/versions/version-a'
        );

        $response
            ->assertOk()
            ->assertViewIs('app');
    }

    public function test_teacher_scoped_workspace_section_query_serves_frontend_application(): void
    {
        $response = $this->get(
            '/teacher/workspace/assignment-a/curricula/curriculum-a/versions/version-a?section=lessons'
        );

        $response
            ->assertOk()
            ->assertViewIs('app');
    }

    public function test_teacher_spa_entry_does_not_shadow_teacher_api_routes(): void
    {
        $response = $this->get('/api/teacher/subject-assignments');

        $response->assertUnauthorized();
    }

    public function test_teacher_spa_entry_does_not_match_unrelated_urls(): void
    {
        $this->get('/teacher-portal')->assertNotFound();
        $this->get('/unrelated')->assertNotFound();
    }
}
