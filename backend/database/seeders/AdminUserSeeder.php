<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => config('sanggah.demo.email')],
            [
                'name' => config('sanggah.demo.name'),
                'password' => config('sanggah.demo.password'),
                'role' => 'admin',
                'email_verified_at' => now(),
            ]
        );
    }
}
