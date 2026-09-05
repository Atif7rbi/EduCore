<?php

use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SessionController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::prefix('auth')->group(function (): void {
    Route::post(
        '/login',
        [SessionController::class, 'login']
    )->middleware('throttle:login');

    Route::post(
        '/forgot-password',
        [PasswordResetController::class, 'requestResetLink']
    )->middleware('throttle:6,1');

    Route::post(
        '/reset-password',
        [PasswordResetController::class, 'reset']
    )->middleware('throttle:6,1');

    Route::middleware(['auth:web', 'active'])->group(function (): void {
        Route::post('/logout', [SessionController::class, 'logout']);
        Route::get('/me', [SessionController::class, 'me']);
    });
});

Route::view('/login', 'app')
    ->name('login');

Route::view('/forgot-password', 'app')
    ->name('password.request');

Route::view('/reset-password/{token}', 'app')
    ->name('password.reset');

Route::view('/app/{path?}', 'app')
    ->where('path', '.*');

Route::view('/admin/{path?}', 'app')
    ->where('path', '.*');
