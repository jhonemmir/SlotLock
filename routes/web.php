<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));

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
