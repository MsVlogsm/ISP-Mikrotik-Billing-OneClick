<?php

namespace App\Livewire\Admin;

use Livewire\Attributes\On;
use Livewire\Component;
use Illuminate\Support\Facades\DB;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class ManageRole extends Component
{
    use WithoutUrlPagination, WithPagination;

    public $roleType;

    public $name;

    public $search;

    public $permissionList = [];

    public $permissions = [];

    public $roleId;

    public $perPage = 10;

    public $confirmingRole = false;

    protected $listeners = ['roleEdit' => 'editRole', 'roleDelete' => 'deleteRole'];

    public function mount(): void
    {
        $this->authorizeRoleManagement();
    }

    /** Role permissions can grant access to every protected module, so only a Super Admin may edit them. */
    private function authorizeRoleManagement(): void
    {
        abort_unless(auth()->user()?->hasRole('Super Admin'), 403, 'Only Super Admin can manage roles and permissions.');
    }

    public function newRole(): void
    {
        $this->authorizeRoleManagement();

        $this->reset(['roleType', 'roleId', 'name', 'permissions']);
        $this->permissionList = Permission::all();
        $this->roleType = 'Create New Role';
        $this->confirmingRole = true;
    }

    public function editRole($roleId): void
    {
        $this->authorizeRoleManagement();

        $this->role = Role::find($roleId);
        if (! $this->role) {
            session()->flash('error', 'Role not found.');

            return;
        }

        if ($this->role->name === 'Super Admin') {
            session()->flash('error', 'Super Admin role cannot be edited.');

            return;
        }

        $this->roleType = 'Edit Role';
        $this->roleId = $roleId;
        $this->name = $this->role->name;
        $this->permissions = $this->role->permissions->pluck('id')->toArray();
        $this->permissionList = Permission::all();
        $this->confirmingRole = true;
    }

    public function saveRole(): void
    {
        $this->authorizeRoleManagement();

        $this->validate([
            'name' => 'required|string|unique:roles,name,'.($this->roleId ?? 'NULL').'|max:255',
            'permissions' => 'array',
            'permissions.*' => 'integer|exists:permissions,id',
        ]);

        if ($this->roleId) {
            $role = Role::find($this->roleId);
            if (! $role) {
                session()->flash('error', 'Role not found.');

                return;
            }
            if ($role->name === 'Super Admin') {
                session()->flash('error', 'Super Admin role cannot be edited.');

                return;
            }
            $role->name = $this->name;
            $role->syncPermissions($this->permissions);
            $role->save();
            flash()->success('Role updated successfully.');
        } else {
            $role = Role::create(['guard_name' => 'web', 'name' => $this->name]);
            $role->syncPermissions($this->permissions);
            flash()->success('Role created successfully.');
        }

        $this->confirmingRole = false;
    }

    public function deleteRole($roleId, $roleName): void
    {
        $this->authorizeRoleManagement();
        if ($roleName === 'Super Admin') {
            session()->flash('error', 'Super Admin role cannot be deleted.');

            return;
        }
        $this->roleId = $roleId;
        sweetalert()
            ->option('confirmButtonText', 'Yes')
            ->showDenyButton()
            ->warning(
                "Are you sure you want to delete this '{$roleName}' role?",
                ['title' => 'Confirm Deletion']
            );
    }

    #[On('sweetalert:confirmed')]
    public function onConfirmed(array $payload = []): void
    {
        $this->authorizeRoleManagement();

        try {
            if (! $this->roleId) {
                session()->flash('error', 'No role selected for deletion.');

                return;
            }

            $role = Role::find($this->roleId);

            if (! $role) {
                session()->flash('error', 'Role not found.');
                $this->roleId = null;

                return;
            }

            if ($role->name === 'Super Admin') {
                session()->flash('error', 'Super Admin role cannot be deleted.');
                $this->roleId = null;

                return;
            }

            $roleIsAssigned = DB::table(config('permission.table_names.model_has_roles', 'model_has_roles'))
                ->where('role_id', $role->id)
                ->exists();
            if ($roleIsAssigned) {
                session()->flash('error', 'Reassign this role to another role before deleting it.');
                $this->roleId = null;

                return;
            }

            $role->delete();
            $this->roleId = null;

            session()->flash('success', 'Role successfully deleted.');
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', 'Unable to delete the role. Please try again.');
        }
    }

    #[On('sweetalert:denied')]
    public function onDeny(array $payload): void
    {
        session()->flash('info', 'Deletion cancelled.');
    }

    public function render()
    {
        $roles = Role::where('name', '!=', 'Reseller')->with('permissions')->orderBy('id')->paginate($this->perPage);

        return view('livewire.admin.role.manage-role', [
            'roles' => $roles,
            'roleMatrix' => config('role_permission_matrix', []),
        ])->layout('layouts.app');
    }
}
