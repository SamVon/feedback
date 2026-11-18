<?php

use App\Http\Controllers\Internal\FeedbackController as C;
use App\Http\Middleware\ServiceIdentity as S;
use Illuminate\Support\Facades\Route;

Route::prefix('internal/v1/feedback')->middleware('throttle:internal')->group(function () {
    Route::get('config', [C::class, 'config'])->middleware(S::class.':config.read');
    Route::post('responses', [C::class, 'submit'])->middleware(S::class.':response.write');
    Route::get('responses/{id}', [C::class, 'read'])->middleware(S::class.':response.read');
    foreach (['eligibility', 'invitations'] as $path) {
        Route::post($path, [C::class, 'eligibility'])->middleware(S::class.':invitation.write');
    }
    Route::post('prompt-events', [C::class, 'event'])->middleware(S::class.':invitation.write');
    Route::post('cohort-snapshots', [C::class, 'start'])->middleware(S::class.':cohort.write');
    Route::post('cohort-snapshots/{id}/members', [C::class, 'members'])->middleware(S::class.':cohort.write');
    Route::post('cohort-snapshots/{id}/complete', [C::class, 'complete'])->middleware(S::class.':cohort.write');
});
