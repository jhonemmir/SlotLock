<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\Resource;
use App\Services\AvailabilityService;
use App\Services\ReservationService;
use App\Services\SimulationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Throwable;

class DemoController extends Controller
{
    public function index(Request $request, AvailabilityService $availability): View
    {
        $resources = Resource::query()->orderBy('name')->get();
        $resource = $request->filled('resource')
            ? $resources->firstWhere('id', $request->query('resource'))
            : $resources->first();
        $resource ??= $resources->first();

        $date = $resource
            ? $this->resolveDate($request->query('date'), $resource)
            : CarbonImmutable::now();

        return view('demo.index', [
            'resources' => $resources,
            'resource' => $resource,
            'date' => $date->toDateString(),
            'availability' => $resource
                ? $availability->forDate($resource, $date)
                : ['date' => $date->toDateString(), 'timezone' => 'UTC', 'slots' => []],
            'maxN' => (int) config('slotlock.simulate_max_n'),
        ]);
    }

    public function availability(Request $request, AvailabilityService $availability): View
    {
        $request->validate([
            'resource' => ['required', 'uuid'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $resource = Resource::query()->findOrFail($request->query('resource'));
        $date = $this->resolveDate($request->query('date'), $resource);

        return view('demo._availability', [
            'resource' => $resource,
            'date' => $date->toDateString(),
            'availability' => $availability->forDate($resource, $date),
        ]);
    }

    public function reserve(
        Request $request,
        ReservationService $reservations,
        AvailabilityService $availability,
    ): View {
        $validator = Validator::make($request->all(), [
            'resource_id' => ['required', 'uuid', 'exists:resources,id'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
        ]);

        if ($validator->fails()) {
            return view('demo._booking-result', [
                'ok' => false,
                'error' => 'invalid_input',
                'message' => $validator->errors()->first(),
                'reservation' => null,
                'resource' => null,
                'availability' => null,
            ]);
        }

        $data = $validator->validated();
        $resource = Resource::query()->findOrFail($data['resource_id']);
        $start = CarbonImmutable::parse($data['start_at'], $resource->timezone);
        $end = CarbonImmutable::parse($data['end_at'], $resource->timezone);

        $reservation = null;
        $ok = true;
        $error = null;
        $message = null;

        try {
            $reservation = $reservations->hold($resource, $start, $end);
        } catch (DomainException $e) {
            $ok = false;
            $error = $e->errorCode();
            $message = $e->getMessage();
        } catch (Throwable $e) {
            report($e);
            $ok = false;
            $error = 'unexpected_error';
            $message = 'Ocurrió un error inesperado. Revisá los logs.';
        }

        $date = $start->setTimezone($resource->timezone)->toDateString();

        return view('demo._booking-result', [
            'ok' => $ok,
            'error' => $error,
            'message' => $message,
            'reservation' => $reservation,
            'resource' => $resource,
            'availability' => $availability->forDate(
                $resource,
                CarbonImmutable::parse($date, $resource->timezone),
            ),
        ]);
    }

    public function simulate(
        Request $request,
        SimulationService $simulation,
        AvailabilityService $availability,
    ): View {
        $validator = Validator::make($request->all(), [
            'resource_id' => ['required', 'uuid', 'exists:resources,id'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'n' => ['required', 'integer', 'min:1', 'max:'.(int) config('slotlock.simulate_max_n')],
        ]);

        if ($validator->fails()) {
            return view('demo._simulation-result', [
                'result' => [
                    'n' => 0,
                    'succeeded' => 0,
                    'rejected' => 0,
                    'errors' => [[
                        'error' => 'invalid_input',
                        'message' => $validator->errors()->first(),
                    ]],
                    'duration_ms' => 0,
                ],
                'availability' => null,
            ]);
        }

        $data = $validator->validated();
        $resource = Resource::query()->findOrFail($data['resource_id']);
        $start = CarbonImmutable::parse($data['start_at'], $resource->timezone);
        $end = CarbonImmutable::parse($data['end_at'], $resource->timezone);

        try {
            $result = $simulation->run($resource, $start, $end, (int) $data['n']);
        } catch (Throwable $e) {
            report($e);
            $result = [
                'n' => (int) $data['n'],
                'succeeded' => 0,
                'rejected' => 0,
                'errors' => [[
                    'error' => 'simulation_failed',
                    'message' => 'No se pudo llegar a la API interna. Revisá SIMULATE_BASE_URL.',
                ]],
                'duration_ms' => 0,
            ];
        }

        $date = $start->setTimezone($resource->timezone)->toDateString();

        return view('demo._simulation-result', [
            'result' => $result,
            'resource' => $resource,
            'availability' => $availability->forDate(
                $resource,
                CarbonImmutable::parse($date, $resource->timezone),
            ),
        ]);
    }

    private function resolveDate(?string $value, Resource $resource): CarbonImmutable
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return CarbonImmutable::parse($value, $resource->timezone);
        }

        // Por defecto, mañana: la demo arranca con slots futuros.
        return CarbonImmutable::now($resource->timezone)->addDay()->startOfDay();
    }
}
