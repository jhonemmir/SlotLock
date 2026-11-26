<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWaitlistRequest;
use App\Http\Resources\WaitlistEntryResource;
use App\Models\Resource;
use App\Models\User;
use App\Services\WaitlistService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class WaitlistController extends Controller
{
    public function store(StoreWaitlistRequest $request, WaitlistService $waitlist): JsonResponse
    {
        $resource = Resource::query()->findOrFail($request->validated('resource_id'));
        $user = User::query()->findOrFail($request->validated('user_id'));

        $start = CarbonImmutable::parse($request->validated('start_at'), $resource->timezone);
        $end = CarbonImmutable::parse($request->validated('end_at'), $resource->timezone);

        $entry = $waitlist->register($resource, $start, $end, $user);

        return response()->json((new WaitlistEntryResource($entry))->resolve(), 201);
    }
}
