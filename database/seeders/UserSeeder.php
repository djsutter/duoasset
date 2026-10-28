<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::query()->firstOrNew(['email' => 'dsutter@qualivera.com']);
        $user->forceFill([
            'name' => 'Admin1',
            'password' => 'admin1111',
            'role' => UserRole::ADMINISTRATOR,
            'view_mode' => 'stocks',
            'email_verified_at' => $user->email_verified_at ?? now(),
            'activation_token' => null,
        ])->save();
        $user = User::query()->firstOrNew(['email' => 'joe@7pro.ca']);
        $user->forceFill([
            'name' => 'Admin2',
            'password' => 'admin1111',
            'role' => UserRole::ADMINISTRATOR,
            'view_mode' => 'stocks',
            'email_verified_at' => $user->email_verified_at ?? now(),
            'activation_token' => null,
        ])->save();
    }
}
