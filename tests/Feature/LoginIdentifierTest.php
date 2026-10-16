<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginIdentifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_sign_in_with_email(): void
    {
        $user = User::factory()->create([
            'email' => 'email-login@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $this->post(route('login'), [
            'email' => 'email-login@example.test',
            'password' => 'correct-password',
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_can_sign_in_with_unique_account_name(): void
    {
        $user = User::factory()->create([
            'name' => 'unique-account-handle',
            'email' => 'name-login@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $this->post(route('login'), [
            'email' => 'unique-account-handle',
            'password' => 'correct-password',
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_duplicate_account_names_are_not_accepted_as_username(): void
    {
        User::factory()->create([
            'name' => 'duplicate-handle',
            'email' => 'first-account@example.test',
            'password' => Hash::make('correct-password'),
        ]);
        User::factory()->create([
            'name' => 'duplicate-handle',
            'email' => 'second-account@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $this->post(route('login'), [
            'email' => 'duplicate-handle',
            'password' => 'correct-password',
        ])->assertSessionHasErrors();

        $this->assertGuest();
    }
}
