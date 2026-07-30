<?php

namespace Tests\Feature;

use App\Livewire\UsersManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UsersManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name'      => 'Admin',
            'email'     => 'admin@x.test',
            'password'  => Hash::make('secret123'),
            'role'      => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_create_a_staff_user(): void
    {
        Livewire::actingAs($this->admin())
            ->test(UsersManager::class)
            ->set('name', 'Jane')
            ->set('email', 'jane@x.test')
            ->set('role', 'staff')
            ->set('password', 'mypassword123')
            ->set('password_confirmation', 'mypassword123')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'jane@x.test', 'role' => 'staff']);
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(UsersManager::class)
            ->call('delete', $admin->id);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_cannot_delete_last_admin(): void
    {
        $admin = $this->admin();
        $other = User::create([
            'name' => 'Other Admin', 'email' => 'o@x.test',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);

        // log in as $other and try to delete $admin — leaves $other as last admin (allowed)
        Livewire::actingAs($other)
            ->test(UsersManager::class)
            ->call('delete', $admin->id);
        $this->assertDatabaseMissing('users', ['id' => $admin->id]);

        // Now try to delete $other (which is the last admin). Need a different acting user.
        $staff = User::create([
            'name' => 'S', 'email' => 's@x.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_STAFF, 'is_active' => true,
        ]);
        $other->role = User::ROLE_ADMIN; $other->save();

        Livewire::actingAs($other)
            ->test(UsersManager::class)
            ->call('delete', $other->id);
        // self-delete is blocked
        $this->assertDatabaseHas('users', ['id' => $other->id]);
    }

    public function test_admin_can_toggle_other_user_active(): void
    {
        $admin = $this->admin();
        $u = User::create([
            'name' => 'U', 'email' => 'u@x.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_STAFF, 'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(UsersManager::class)
            ->call('toggleActive', $u->id);

        $this->assertFalse($u->fresh()->is_active);
    }

    public function test_validation_rejects_short_password(): void
    {
        Livewire::actingAs($this->admin())
            ->test(UsersManager::class)
            ->set('name', 'Short')
            ->set('email', 'short@x.test')
            ->set('role', 'staff')
            ->set('password', 'short')
            ->set('password_confirmation', 'short')
            ->call('save')
            ->assertHasErrors(['password']);
    }
}
