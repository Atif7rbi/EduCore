<?php

use App\Http\Controllers\Auth\SessionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('auth')->group(function (): void {
    Route::post(
        '/login',
        [SessionController::class, 'login']
    )->middleware('throttle:login');

    Route::middleware(['auth:web', 'active'])->group(function (): void {
        Route::post('/logout', [SessionController::class, 'logout']);
        Route::get('/me', [SessionController::class, 'me']);
    });
});

Route::view('/login', 'app');

Route::view('/app/{path?}', 'app')
    ->where('path', '.*');

Route::view('/admin/{path?}', 'app')
    ->where('path', '.*');
