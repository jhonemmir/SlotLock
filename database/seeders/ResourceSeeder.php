<?php

namespace Database\Seeders;

use App\Models\Resource;
use Illuminate\Database\Seeder;

class ResourceSeeder extends Seeder
{
    public function run(): void
    {
        // UUIDs fijos: el seed es idempotente y los ejemplos del README
        // apuntan siempre a los mismos recursos.
        $resources = [
            [
                'id' => '00000000-0000-4000-8000-000000000001',
                'name' => 'Sala A',
                'type' => 'room',
                'capacity' => 1,
                'timezone' => 'America/Argentina/Buenos_Aires',
                'max_duration_minutes' => 240,
                'opening_hours' => [
                    'mon' => ['08:00', '22:00'],
                    'tue' => ['08:00', '22:00'],
                    'wed' => ['08:00', '22:00'],
                    'thu' => ['08:00', '22:00'],
                    'fri' => ['08:00', '22:00'],
                    'sat' => ['09:00', '20:00'],
                    'sun' => ['09:00', '20:00'],
                ],
            ],
            [
                'id' => '00000000-0000-4000-8000-000000000002',
                'name' => 'Cancha 1',
                'type' => 'court',
                'capacity' => 1,
                'timezone' => 'America/Argentina/Buenos_Aires',
                'max_duration_minutes' => 120,
                'opening_hours' => [
                    'mon' => ['07:00', '23:00'],
                    'tue' => ['07:00', '23:00'],
                    'wed' => ['07:00', '23:00'],
                    'thu' => ['07:00', '23:00'],
                    'fri' => ['07:00', '23:00'],
                    'sat' => ['07:00', '23:00'],
                    'sun' => ['07:00', '23:00'],
                ],
            ],
            [
                'id' => '00000000-0000-4000-8000-000000000003',
                'name' => 'Mesa 1',
                'type' => 'table',
                'capacity' => 1,
                'timezone' => 'America/Argentina/Buenos_Aires',
                'max_duration_minutes' => 180,
                'opening_hours' => [
                    'mon' => ['12:00', '23:30'],
                    'tue' => ['12:00', '23:30'],
                    'wed' => ['12:00', '23:30'],
                    'thu' => ['12:00', '23:30'],
                    'fri' => ['12:00', '23:30'],
                    'sat' => ['12:00', '23:30'],
                    'sun' => ['12:00', '22:00'],
                ],
            ],
        ];

        foreach ($resources as $resource) {
            Resource::query()->updateOrCreate(
                ['id' => $resource['id']],
                $resource,
            );
        }
    }
}
