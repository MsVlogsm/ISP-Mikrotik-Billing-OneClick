<?php

namespace Tests\Feature;

use App\Livewire\Admin\ManageRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RolePermissionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure the newly introduced role matrix is loaded even when a prior cached-config snapshot is present.
        config(['role_permission_matrix' => require base_path('config/role_permission_matrix.php')]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['Super Admin', 'Admin', 'Manager', 'Reseller'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $permissionNames = collect(config('role_permission_matrix'))
            ->pluck('permissions')->flatten()->flatten()->unique()->merge([
                'mikrotik-setup', 'edit-user-role', 'payment-delete',
            ])->unique()->values();
        foreach ($permissionNames as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (['Admin', 'Manager'] as $roleName) {
            $names = collect(config("role_permission_matrix.{$roleName}.permissions"))->flatten()->unique();
            Role::findByName($roleName, 'web')->syncPermissions(
                Permission::query()->where('guard_name', 'web')->whereIn('name', $names)->get()
            );
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_admin_and_manager_have_distinct_default_permissions(): void
    {
        $admin = Role::findByName('Admin', 'web');
        $manager = Role::findByName('Manager', 'web');

        $adminPermissions = $admin->permissions->pluck('name')->all();
        $managerPermissions = $manager->permissions->pluck('name')->all();

        $this->assertSame([], array_values(array_diff($managerPermissions, $adminPermissions)), 'Manager permissions should be a subset of Admin permissions.');
        $this->assertTrue($admin->hasPermissionTo('delete-customer'));
        $this->assertTrue($admin->hasPermissionTo('update-bill'));
        $this->assertTrue($admin->hasPermissionTo('create-user'));
        $this->assertFalse($admin->hasPermissionTo('mikrotik-setup'));
        $this->assertFalse($admin->hasPermissionTo('edit-user-role'));

        $this->assertTrue($manager->hasPermissionTo('create-customer'));
        $this->assertTrue($manager->hasPermissionTo('payment-collection'));
        $this->assertTrue($manager->hasPermissionTo('manage-tickets'));
        $this->assertFalse($manager->hasPermissionTo('delete-customer'));
        $this->assertFalse($manager->hasPermissionTo('update-bill'));
        $this->assertFalse($manager->hasPermissionTo('payment-delete'));
        $this->assertFalse($manager->hasPermissionTo('create-user'));
        $this->assertFalse($manager->hasPermissionTo('mikrotik-setup'));
    }

    public function test_manager_cannot_bypass_customer_permissions_through_direct_actions(): void
    {
        $manager = User::factory()->create(['email_verified_at' => now()]);
        $manager->assignRole(Role::findByName('Manager', 'web'));
        $this->actingAs($manager);

        $this->post('/customer/'.encrypt('RBAC-TEST-CUSTOMER').'/actions/grace', [
            'days' => 7,
            'hours' => 0,
        ])->assertForbidden();

        $this->post('/customer/'.encrypt('RBAC-TEST-CUSTOMER').'/actions/password', [
            'password' => 'new-password',
            'confirm_password' => 'new-password',
        ])->assertForbidden();

        $this->get('/customer/'.encrypt('RBAC-TEST-CUSTOMER').'/actions/wifi-login')
            ->assertForbidden();
    }

    public function test_admin_and_manager_cannot_open_role_editor_or_user_editor_without_permissions(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(Role::findByName('Admin', 'web'));
        $this->actingAs($admin)->get('/admin-roles')->assertForbidden();

        $manager = User::factory()->create(['email_verified_at' => now()]);
        $manager->assignRole(Role::findByName('Manager', 'web'));
        $this->actingAs($manager)->get('/admin-users')->assertForbidden();
    }

    public function test_super_admin_can_open_role_editor_and_matrix_is_defined(): void
    {
        $superAdmin = User::factory()->create(['email_verified_at' => now()]);
        $superAdmin->assignRole(Role::findByName('Super Admin', 'web'));

        $this->actingAs($superAdmin)->get('/admin-roles')
            ->assertOk()
            ->assertSee('Role Permission Matrix');

        $this->assertArrayHasKey('Admin', config('role_permission_matrix'));
        $this->assertArrayHasKey('Manager', config('role_permission_matrix'));

        $matrix = config('role_permission_matrix');
        $this->assertArrayHasKey('Admin', $matrix);
        $this->assertArrayHasKey('Manager', $matrix);
        $this->assertNotEmpty($matrix['Admin']['capabilities']);
        $this->assertNotEmpty($matrix['Manager']['restrictions']);
    }
}
