<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /** Default admin account (same as old/prisma/seed.ts). Change the password after first login. */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@lalogix.com'],
            ['name' => 'Admin', 'password' => Hash::make('admin123'), 'role' => 'admin'],
        );

        $this->command?->info('Default admin: admin@lalogix.com / admin123');
    }
}
