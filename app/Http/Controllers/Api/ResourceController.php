<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResourceResource;
use App\Models\Resource;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ResourceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ResourceResource::collection(
            Resource::query()->orderBy('name')->get()
        );
    }

    public function availability(
        Request $request,
        Resource $resource,
        AvailabilityService $availability,
    ): JsonResponse {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $date = isset($data['date'])
            ? CarbonImmutable::parse($data['date'], $resource->timezone)
            : CarbonImmutable::now($resource->timezone);

        return response()->json($availability->forDate($resource, $date));
    }
}
