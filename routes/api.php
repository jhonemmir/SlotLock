<?php

use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\ResourceController;
use App\Http\Controllers\Api\SimulationController;
use App\Http\Controllers\Api\WaitlistController;
use App\Http\Middleware\EnsureApiKey;
use Illuminate\Support\Facades\Route;

// Lecturas públicas: recursos y disponibilidad.
Route::get('/resources', [ResourceController::class, 'index']);
Route::get('/resources/{resource}/availability', [ResourceController::class, 'availability']);

// Escrituras: requieren X-Demo-Key (gatekeeping, no auth).
Route::middleware(EnsureApiKey::class)->group(function () {
    Route::post('/reservations', [ReservationController::class, 'store']);
    Route::post('/reservations/{reservation}/confirm', [ReservationController::class, 'confirm']);
    Route::delete('/reservations/{reservation}', [ReservationController::class, 'destroy']);

    Route::post('/waitlist', [WaitlistController::class, 'store'])
        ->middleware('throttle:30,1');

    Route::post('/simulate/reservations', [SimulationController::class, 'store'])
        ->middleware('throttle:5,1');
});
