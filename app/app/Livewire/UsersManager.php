<?php

namespace App\Livewire;

use App\Models\User;
use App\Support\AuthorizesLivewireWrite;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class UsersManager extends Component
{
    use AuthorizesLivewireWrite;
    use WithPagination;

    public ?int $editingId = null;
    public string $name = '';
    public string $email = '';
    public string $role = User::ROLE_STAFF;
    public bool $is_active = true;
    public string $password = '';
    public string $password_confirmation = '';
    public string $search = '';
    public bool $showForm = false;

    protected function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:191'],
            'email'     => ['required', 'email', 'max:191', Rule::unique('users', 'email')->ignore($this->editingId)],
            'role'      => ['required', Rule::in(User::ROLES)],
            'is_active' => ['boolean'],
            'password'  => array_merge(
                $this->editingId ? ['nullable'] : ['required'],
                ['string', 'min:8', 'confirmed']
            ),
        ];
    }

    /**
     * mount() runs ONLY on the first render. Every later interaction arrives
     * as a POST to /livewire/update which re-hydrates the component WITHOUT
     * calling mount() — so a check placed only here protected nothing: a
     * viewer could hydrate this component and call save() to mint themselves
     * an admin account. Every mutating method below re-checks.
     */
    public function mount(): void
    {
        $this->assertAdmin();
    }

    public function openCreate(): void
    {
        $this->assertAdmin();

        $this->resetForm();
        $this->showForm = true;
    }

    public function openEdit(int $id): void
    {
        $this->assertAdmin();

        $user = User::findOrFail($id);
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role = $user->role ?? User::ROLE_STAFF;
        $this->is_active = (bool) $user->is_active;
        $this->password = '';
        $this->password_confirmation = '';
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->assertAdmin();

        $data = $this->validate();

        if ($this->editingId) {
            $user = User::findOrFail($this->editingId);
            if ($user->id === auth()->id() && $this->role !== User::ROLE_ADMIN) {
                $this->addError('role', __('users.cannot_demote_self'));
                return;
            }
            if ($user->id === auth()->id() && ! $this->is_active) {
                $this->addError('is_active', __('users.cannot_disable_self'));
                return;
            }
            $user->fill([
                'name'      => $data['name'],
                'email'     => $data['email'],
                'role'      => $data['role'],
                'is_active' => (bool) $this->is_active,
            ]);
            if (! empty($data['password'])) {
                $user->password = Hash::make($data['password']);
            }
            $user->save();
            $this->dispatch('flash', message: __('flash.user_updated'));
        } else {
            User::create([
                'name'      => $data['name'],
                'email'     => $data['email'],
                'role'      => $data['role'],
                'is_active' => (bool) $this->is_active,
                'password'  => Hash::make($data['password']),
            ]);
            $this->dispatch('flash', message: __('flash.user_created'));
        }

        $this->resetForm();
        $this->showForm = false;
    }

    public function toggleActive(int $id): void
    {
        $this->assertAdmin();

        $user = User::findOrFail($id);
        if ($user->id === auth()->id()) {
            $this->dispatch('flash', message: __('users.cannot_disable_self'), type: 'error');
            return;
        }
        $user->is_active = ! $user->is_active;
        $user->save();
        $this->dispatch('flash', message: __('flash.user_updated'));
    }

    public function delete(int $id): void
    {
        $this->assertAdmin();

        $user = User::findOrFail($id);
        if ($user->id === auth()->id()) {
            $this->dispatch('flash', message: __('users.cannot_delete_self'), type: 'error');
            return;
        }
        $adminsLeft = User::where('role', User::ROLE_ADMIN)->where('id', '!=', $user->id)->count();
        if ($user->role === User::ROLE_ADMIN && $adminsLeft === 0) {
            $this->dispatch('flash', message: __('users.cannot_delete_last_admin'), type: 'error');
            return;
        }
        $user->delete();
        $this->dispatch('flash', message: __('flash.user_deleted'));
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->email = '';
        $this->role = User::ROLE_STAFF;
        $this->is_active = true;
        $this->password = '';
        $this->password_confirmation = '';
        $this->resetErrorBag();
    }

    #[Computed]
    public function users()
    {
        $q = User::query();
        if ($this->search !== '') {
            $q->where(function ($w) {
                $w->where('name', 'like', '%'.$this->search.'%')
                  ->orWhere('email', 'like', '%'.$this->search.'%');
            });
        }
        return $q->orderBy('id')->paginate(20);
    }

    public function render()
    {
        return view('livewire.users-manager', [
            'users' => $this->users,
        ]);
    }
}
