<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CaptionController;
use App\Http\Controllers\VideoController;

Route::middleware('auth.firebase')->group(function () {
    Route::post('/captions', [CaptionController::class, 'generate']);
    Route::get('/captions/recent', [CaptionController::class, 'recent']);
    Route::post('/videos', [VideoController::class, 'create']);
    Route::get('/videos/recent', [VideoController::class, 'recent']);
    Route::get('/videos/{jobId}', [VideoController::class, 'status']);
});
