<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CaptionController;

Route::middleware('auth.firebase')->group(function () {
    Route::post('/captions', [CaptionController::class, 'generate']);
});
