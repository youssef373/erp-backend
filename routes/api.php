<?php

use App\Http\Controllers\Auth\SpaAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name'),
        'status' => 'ok',
        'laravel' => app()->version(),
    ]);
});

// Cookie-based SPA auth (call GET /sanctum/csrf-cookie first).
Route::post('/login', [SpaAuthController::class, 'login']);
Route::post('/logout', [SpaAuthController::class, 'logout'])->middleware('auth:sanctum');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
