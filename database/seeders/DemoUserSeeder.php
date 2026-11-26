<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        // Usuario fijo para los ejemplos de la API (sin auth en v1).
        User::query()->updateOrCreate(
            ['id' => '00000000-0000-4000-8000-000000000101'],
            [
                'name' => 'Demo User',
                'email' => 'demo@slotlock.local',
                'password' => Hash::make(Str::random(32)),
            ],
        );
    }
}
