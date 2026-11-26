<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSimulationRequest;
use App\Models\Resource;
use App\Services\SimulationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class SimulationController extends Controller
{
    public function store(StoreSimulationRequest $request, SimulationService $simulation): JsonResponse
    {
        $resource = Resource::query()->findOrFail($request->validated('resource_id'));

        $start = CarbonImmutable::parse($request->validated('start_at'), $resource->timezone);
        $end = CarbonImmutable::parse($request->validated('end_at'), $resource->timezone);

        return response()->json(
            $simulation->run($resource, $start, $end, (int) $request->validated('n'))
        );
    }
}
