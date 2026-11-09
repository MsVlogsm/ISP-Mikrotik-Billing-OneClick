<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class P1PermissionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(Role::findOrCreate($role, 'web'));

        return $user;
    }

    public function test_admin_and_manager_cannot_read_or_mutate_sms_gateway_credentials(): void
    {
        foreach (['Admin', 'Manager'] as $role) {
            $this->actingAs($this->userWithRole($role));

            $this->get('/sms-bridge')->assertForbidden();
            $this->post('/sms-bridge/store', [
                'name' => 'Unauthorized gateway',
                'api_url' => 'https://sms.example.test/send',
                'api_key' => 'not-a-real-key',
                'sender_id' => 'XLINK',
                'priority' => 1,
            ])->assertForbidden();
            $this->put('/sms-bridge/1', [])->assertForbidden();
            $this->put('/sms-bridge/1/toggle', [])->assertForbidden();
            $this->get('/sms-bridge/1/balance')->assertForbidden();
            $this->post('/sms-bridge/1/test', [
                'phone' => '01700000000',
                'message' => 'Permission test',
            ])->assertForbidden();
            $this->delete('/sms-bridge/1')->assertForbidden();
        }
    }

    public function test_super_admin_retains_sms_gateway_access(): void
    {
        $this->actingAs($this->userWithRole('Super Admin'));

        $this->get('/sms-bridge')->assertOk();
    }
}
