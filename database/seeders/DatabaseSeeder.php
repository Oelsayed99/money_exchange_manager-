<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Reference data first: currencies are a prerequisite for every financial
        // record the system will hold, and roles must exist before a user can hold one.
        $this->call([
            RolePermissionSeeder::class,
            CurrencySeeder::class,
        ]);

        User::factory()
            ->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ])
            ->assignRole(Role::Owner->value);
    }
}
