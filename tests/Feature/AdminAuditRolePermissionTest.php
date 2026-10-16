<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAuditRolePermissionTest extends TestCase
{
    use RefreshDatabase;

    private function loginAsRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(Role::findByName($roleName, 'web'));
        $this->actingAs($user);
        return $user;
    }

    public function test_manager_is_denied_admin_financial_and_audit_tools(): void
    {
        $this->loginAsRole('Manager');

        foreach ([
            '/admin/resellers',
            '/admin/expenses',
            '/admin/activity-logs',
            '/admin/login-logs',
            '/admin/reviews',
            '/admin/vouchers',
            '/admin/purchase-requests',
            '/admin/system-logs',
            '/communication-center/settings',
            '/site-settings',
        ] as $path) {
            $this->get($path)->assertForbidden("Manager should not access {$path}.");
        }

        $this->post('/admin/resellers', [])->assertForbidden();
    }

    public function test_admin_can_access_assigned_business_admin_tools_but_not_owner_system_logs(): void
    {
        $this->loginAsRole('Admin');

        foreach ([
            '/admin/resellers',
            '/admin/expenses',
            '/admin/activity-logs',
            '/admin/login-logs',
            '/admin/reviews',
            '/admin/vouchers',
            '/admin/purchase-requests',
        ] as $path) {
            $this->get($path)->assertOk("Admin should access {$path}.");
        }

        $this->get('/admin/system-logs')->assertForbidden();
        $this->get('/site-settings')->assertForbidden();
    }

    public function test_admin_only_permissions_are_not_assigned_to_manager(): void
    {
        $admin = Role::findByName('Admin', 'web');
        $manager = Role::findByName('Manager', 'web');

        $adminOnly = [
            'manage-resellers',
            'manage-expenses',
            'view-audit-logs',
            'manage-reviews',
            'view-vouchers',
            'manage-purchase-requests',
            'view-profit-summary',
        ];

        foreach ($adminOnly as $permission) {
            $this->assertTrue($admin->hasPermissionTo($permission));
            $this->assertFalse($manager->hasPermissionTo($permission));
        }
    }
}
