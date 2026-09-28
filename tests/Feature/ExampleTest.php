<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_root_redirects_to_login_for_a_guest(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }
}
