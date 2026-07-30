<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DefaultAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (User::where('role', User::ROLE_ADMIN)->exists()) {
            return;
        }

        User::updateOrCreate(
            ['email' => 'admin@alboukhari.local'],
            [
                'name'      => 'Admin',
                'password'  => Hash::make('Admin@2026'),
                'role'      => User::ROLE_ADMIN,
                'is_active' => true,
            ]
        );

        $this->command?->warn('Default admin created: admin@alboukhari.local / Admin@2026 — please change the password after first login.');
    }
}
