<?php

use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

Route::get('/health', fn () => response()->json([
    'ok' => true,
    'service' => 'scrollstop-backend',
]))->withoutMiddleware([
    StartSession::class,
    ShareErrorsFromSession::class,
    VerifyCsrfToken::class,
]);
