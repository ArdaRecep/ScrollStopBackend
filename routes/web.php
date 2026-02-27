<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CaptionController;

Route::get('/health', fn () => response()->json([
  'ok' => true,
  'service' => 'scrollstop-backend',
]));

Route::middleware('auth.firebase')->group(function () {
  Route::post('/captions', [CaptionController::class, 'generate']);
});