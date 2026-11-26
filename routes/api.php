<?php

use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\ResourceController;
use App\Http\Controllers\Api\SimulationController;
use Illuminate\Support\Facades\Route;

Route::get('/resources', [ResourceController::class, 'index']);
Route::get('/resources/{resource}/availability', [ResourceController::class, 'availability']);

Route::post('/reservations', [ReservationController::class, 'store']);
Route::post('/reservations/{reservation}/confirm', [ReservationController::class, 'confirm']);
Route::delete('/reservations/{reservation}', [ReservationController::class, 'destroy']);

Route::post('/simulate/reservations', [SimulationController::class, 'store'])
    ->middleware('throttle:5,1');
