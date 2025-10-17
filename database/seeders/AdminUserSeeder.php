<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create admin user if it doesn't exist
        User::firstOrCreate(
            ['email' => 'admin@soil-erosion.tj'],
            [
                'name' => 'Administrator',
                'password' => Hash::make('admin123'),
                'role' => 'admin',
                'email_verified_at' => now(),
            ]
        );

        // Create a regular user for testing
        User::firstOrCreate(
            ['email' => 'user@soil-erosion.tj'],
            [
                'name' => 'Test User',
                'password' => Hash::make('user123'),
                'role' => 'user',
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('Admin users created successfully!');
        $this->command->info('Admin: admin@soil-erosion.tj / admin123');
        $this->command->info('User: user@soil-erosion.tj / user123');
    }
}
