<?php

use App\Http\Controllers\CustomerFeedbackController;
use App\Http\Middleware\CustomerFeedbackIdentity;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/feedback')->middleware(['throttle:internal', CustomerFeedbackIdentity::class])->group(function () {
    Route::get('config', [CustomerFeedbackController::class, 'config']);
    Route::post('eligibility', [CustomerFeedbackController::class, 'eligibility']);
    Route::post('prompt-events', [CustomerFeedbackController::class, 'event']);
    Route::post('responses', [CustomerFeedbackController::class, 'submit']);
});
