<?php

use App\Application\Exports;
use App\Models\ExportJob;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));
Route::get('/staff/exports/{job}', function (ExportJob $job, Exports $service) {
    return $service->download(auth()->user(), $job);
})->middleware('auth')->name('exports.download');
Route::get('/staff/locale/{locale}', function (string $locale) {
    abort_unless(in_array($locale, ['en', 'lo'], true), 404);
    session(['feedback_locale' => $locale]);

    return redirect('/admin');
})->middleware('auth')->name('staff.locale');
