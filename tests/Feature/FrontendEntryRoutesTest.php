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
}
