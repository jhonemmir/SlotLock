<?php

use App\Http\Controllers\DemoController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));

Route::get('/demo', [DemoController::class, 'index'])->name('demo');
Route::get('/demo/availability', [DemoController::class, 'availability']);
Route::post('/demo/reservations', [DemoController::class, 'reserve'])
    ->middleware('throttle:20,1');
Route::post('/demo/simulate', [DemoController::class, 'simulate'])
    ->middleware('throttle:10,1');

Route::get('/health', function () {
    try {
        DB::select('select 1');
        $database = 'ok';
    } catch (Throwable) {
        $database = 'down';
    }

    return response()->json([
        'status' => $database === 'ok' ? 'ok' : 'degraded',
        'app' => config('app.name'),
        'database' => $database,
        'time' => now()->toIso8601String(),
    ], $database === 'ok' ? 200 : 503);
});
