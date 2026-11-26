<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResourceSeeder extends Seeder
{
    public function run(): void
    {
        $resources = [
            [
                'name' => 'Sala A',
                'type' => 'room',
                'capacity' => 1,
                'timezone' => 'America/Argentina/Buenos_Aires',
                'opening_hours' => ['mon' => ['08:00', '22:00'], 'tue' => ['08:00', '22:00'], 'wed' => ['08:00', '22:00'], 'thu' => ['08:00', '22:00'], 'fri' => ['08:00', '22:00'], 'sat' => ['09:00', '20:00'], 'sun' => ['09:00', '20:00']],
            ],
            [
                'name' => 'Cancha 1',
                'type' => 'court',
                'capacity' => 1,
                'timezone' => 'America/Argentina/Buenos_Aires',
                'opening_hours' => ['mon' => ['07:00', '23:00'], 'tue' => ['07:00', '23:00'], 'wed' => ['07:00', '23:00'], 'thu' => ['07:00', '23:00'], 'fri' => ['07:00', '23:00'], 'sat' => ['07:00', '23:00'], 'sun' => ['07:00', '23:00']],
            ],
            [
                'name' => 'Mesa 1',
                'type' => 'table',
                'capacity' => 1,
                'timezone' => 'America/Argentina/Buenos_Aires',
                'opening_hours' => ['mon' => ['12:00', '23:30'], 'tue' => ['12:00', '23:30'], 'wed' => ['12:00', '23:30'], 'thu' => ['12:00', '23:30'], 'fri' => ['12:00', '23:30'], 'sat' => ['12:00', '00:00'], 'sun' => ['12:00', '22:00']],
            ],
        ];

        foreach ($resources as $r) {
            DB::table('resources')->insert([
                'id' => (string) Str::uuid(),
                'name' => $r['name'],
                'type' => $r['type'],
                'capacity' => $r['capacity'],
                'timezone' => $r['timezone'],
                'opening_hours' => json_encode($r['opening_hours']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
