<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_are_redirected_to_their_team_dashboard()
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $response = $this
            ->actingAs($user)
            ->get('/');

        $response->assertRedirect(route('dashboard', ['current_team' => $team->slug]));
    }
}
