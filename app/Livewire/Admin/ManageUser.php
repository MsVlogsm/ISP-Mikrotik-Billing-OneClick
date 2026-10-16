<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Rules\ValidPhoneDigits;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

class ManageUser extends Component
{
    use WithoutUrlPagination, WithPagination;

    public $user;

    public $userType;

    public $name;

    public $email;

    public $address;

    public $password;

    public $password_confirmation;

    public $userId;

    public $userRoles;

    public $mobile = '880';

    public $roles = [];

    public $search = '';

    public $perPage = 10;

    public function updatedRoles($value)
    {
        if (in_array('Super Admin', $this->roles)) {
            $this->roles = ['Super Admin'];
        }
    }

    /**
     * Indicates if the model is being confirmed.
     *
     * @var bool
     */
    public $confirmingUser = false;

    protected $listeners = ['userEdit' => 'editUser', 'userDelete' => 'deleteUser'];

    protected $messages = [
        'mobile.regex' => 'Enter an 11-digit Bangladesh mobile number starting with 01.',
    ];

    public function mount()
    {
        if (! hasAccess(['Super Admin'], ['create-user', 'edit-user', 'view-user'])) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function assignableRoleNames(User $actor): array
    {
        $query = Role::query()->where('name', '!=', 'Reseller');

        if (! $actor->hasRole('Super Admin')) {
            $allowedPermissions = $actor->getAllPermissions()->pluck('name')->all();
            $query->where('name', '!=', 'Super Admin')
                ->whereDoesntHave('permissions', function ($permissions) use ($allowedPermissions) {
                    $permissions->whereNotIn('name', $allowedPermissions);
                });
        }

        return $query->orderBy('name')->pluck('name')->all();
    }

    public function userRoles(): void
    {
        $actor = auth()->user();
        $this->userRoles = $actor ? $this->assignableRoleNames($actor) : [];
    }

    public function newUser(): void
    {
        if (abortIfNoAccess(['Super Admin'], ['create-user'], 'You do not have permission to create users.')) {
            return;
        }

        $this->reset(['user', 'name', 'email', 'mobile', 'address', 'password', 'password_confirmation', 'userId', 'roles']);
        $this->userRoles();
        $this->userType = 'Create New User';
        $this->confirmingUser = true;
    }

    public function editUser($userId)
    {
        if (abortIfNoAccess(['Super Admin'], ['edit-user'], 'You do not have permission to edit users.')) {
            return;
        }

        $targetUser = User::find($userId);
        if (! $targetUser) {
            flash()->error('User not found.');
            return;
        }
        if ($targetUser->hasRole('Super Admin') && ! auth()->user()->hasRole('Super Admin')) {
            flash()->error('Only Super Admins can edit other Super Admins.');
            return;
        }

        $this->userRoles();

        $this->userType = 'Edit User';
        $this->userId = $userId;
        $this->user = $targetUser->makeHidden('password');
        $this->name = $this->user->name;
        $this->email = $this->user->email;
        $this->mobile = $this->user->mobile ?? '880';
        $this->address = $this->user->address;
        $this->roles = $this->user->getRoleNames()->toArray();
        $this->confirmingUser = true;
    }

    public function deleteUser($userId, $UserName, $userEmail): void
    {
        if (abortIfNoAccess(['Super Admin'], ['delete-user'], 'You do not have permission to delete users.')) {
            return;
        }

        $targetUser = User::find($userId);
        if (! $targetUser) {
            flash()->error('User not found.');
            return;
        }
        if ($targetUser->id === auth()->id()) {
            flash()->error('You cannot delete your own account from User Management.');
            return;
        }
        if ($targetUser->hasRole('Super Admin') && ! auth()->user()->hasRole('Super Admin')) {
            flash()->error('Only Super Admins can delete other Super Admins.');
            return;
        }

        $this->userId = $userId;

        sweetalert()
            ->option('confirmButtonText', 'Yes')
            ->showDenyButton()
            ->warning(
                "Are you sure you want to delete {$UserName} ({$userEmail})?",
                ['title' => 'Confirm Deletion']
            );
    }

    #[On('sweetalert:confirmed')]
    public function onConfirmed(array $payload = []): void
    {
        $actor = auth()->user();
        abort_unless($actor && ($actor->hasRole('Super Admin') || $actor->can('delete-user')), 403, 'You do not have permission to delete users.');

        $targetUser = User::find($this->userId);
        if (! $targetUser) {
            $this->userId = null;
            flash()->error('User not found.');
            return;
        }
        if ($targetUser->id === $actor->id) {
            $this->userId = null;
            flash()->error('You cannot delete your own account from User Management.');
            return;
        }
        if ($targetUser->hasRole('Super Admin') && ! $actor->hasRole('Super Admin')) {
            $this->userId = null;
            flash()->error('Only Super Admins can delete other Super Admins.');
            return;
        }
        if ($targetUser->hasRole('Super Admin') && User::role('Super Admin')->count() <= 1) {
            $this->userId = null;
            flash()->error('The last Super Admin account cannot be deleted.');
            return;
        }

        $targetUser->delete();
        $this->userId = null;
        flash()->success('User successfully deleted.');
    }

    #[On('sweetalert:denied')]
    public function onDeny(array $payload): void
    {
        flash()->info('Deletion cancelled.');
    }

    public function submitUser(): void
    {
        $actor = auth()->user();
        abort_unless($actor, 403, 'Authentication required.');

        $wasEditing = filled($this->userId);
        $existingUser = null;
        if ($wasEditing) {
            abort_unless($actor->hasRole('Super Admin') || $actor->can('edit-user'), 403, 'You do not have permission to edit users.');
            $existingUser = User::find($this->userId);
            if (! $existingUser) {
                flash()->error('User not found.');
                return;
            }
            if ($existingUser->hasRole('Super Admin') && ! $actor->hasRole('Super Admin')) {
                flash()->error('Only Super Admins can edit other Super Admins.');
                return;
            }
        } else {
            abort_unless($actor->hasRole('Super Admin') || $actor->can('create-user'), 403, 'You do not have permission to create users.');
        }

        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email:rfc,dns|max:255|unique:users,email,'.$this->userId,
            'mobile' => ['nullable', 'string', 'regex:/^01[3-9][0-9]{8}$/'],
            'address' => 'nullable|string|max:255',
            'roles' => 'required|array|min:1',
            'roles.*' => 'required|string|distinct|exists:roles,name',
        ];

        // Conditionally apply password rules
        if (! $this->userId) {
            $rules['password'] = 'required|string|min:8|confirmed';
            $rules['password_confirmation'] = 'required|string|min:8';
        } else {
            $rules['password'] = 'nullable|string|min:8|confirmed';
            $rules['password_confirmation'] = 'nullable|string|min:8';
        }

        $this->validate($rules);

        $selectedRoles = array_values(array_unique($this->roles));
        if (in_array('Reseller', $selectedRoles, true)) {
            flash()->error('Reseller accounts must be managed from the Reseller module.');
            return;
        }

        if (in_array('Super Admin', $selectedRoles, true)) {
            if (! $actor->hasRole('Super Admin') || $selectedRoles !== ['Super Admin']) {
                flash()->error('Only Super Admins can assign the Super Admin role, and it must be the only selected role.');
                return;
            }
        } elseif (! $actor->hasRole('Super Admin')) {
            $assignableRoles = $this->assignableRoleNames($actor);
            if (array_diff($selectedRoles, $assignableRoles)) {
                flash()->error('You cannot assign a role with permissions beyond your own access level.');
                return;
            }
        }

        if ($existingUser && $existingUser->id === $actor->id && ! $actor->hasRole('Super Admin')) {
            $currentRoles = $existingUser->getRoleNames()->sort()->values()->all();
            $newRoles = collect($selectedRoles)->sort()->values()->all();
            if ($currentRoles !== $newRoles) {
                flash()->error('You cannot change your own role. Ask a Super Admin to do that.');
                return;
            }
        }

        if ($existingUser && $existingUser->hasRole('Super Admin') && ! in_array('Super Admin', $selectedRoles, true)
            && User::role('Super Admin')->count() <= 1) {
            flash()->error('The last Super Admin account cannot lose its Super Admin role.');
            return;
        }

        $userData = [
            'name' => $this->name,
            'email' => $this->email,
            'mobile' => $this->mobile ?: null,
            'address' => $this->address ?: null,
        ];

        if (! $wasEditing || filled($this->password)) {
            $userData['password'] = bcrypt($this->password);
        }

        if ($wasEditing) {
            $existingUser->fill($userData);
            $existingUser->save();
            $savedUser = $existingUser;
        } else {
            $savedUser = User::create($userData);
        }

        $savedUser->syncRoles($selectedRoles);

        $this->reset([
            'name',
            'email',
            'mobile',
            'address',
            'password',
            'password_confirmation',
            'userId',
        ]);
        $this->user = null;
        $this->userType = null;
        $this->roles = [];

        if ($wasEditing) {
            flash()->success('User has been updated successfully.');
        } else {
            flash()->success('User has been created successfully.');
        }

        $this->confirmingUser = false;
    }

    /**
     * Render the component.
     *
     * @return View
     */
    public function render()
    {
        $users = User::search($this->search)
            ->whereDoesntHave('roles', function ($query) {
                $query->where('name', 'Reseller');
            })
            ->paginate($this->perPage);

        return view('livewire.admin.user.manage-user', ['users' => $users])->layout('layouts.app');
    }
}
