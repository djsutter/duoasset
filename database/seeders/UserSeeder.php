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
        User::query()->updateOrCreate(
            ['email' => 'demo@crypto.x'],
            [
                'name' => 'Admin',
                'password' => 'admin1111',
                'role' => UserRole::ADMINISTRATOR,
                'view_mode' => 'stocks',
            ]
        );
    }
}
