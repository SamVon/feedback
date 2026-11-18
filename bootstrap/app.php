<?php

use App\Http\InternalErrors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: fn () => Route::middleware('api')->group(base_path('routes/internal.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo('/admin/login');
        $middleware->trimStrings(except: [fn (Request $request) => $request->is('internal/*')]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request) => $request->is('internal/*')]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (Throwable $error, Request $request) => InternalErrors::render($error, $request));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('internal/*') || $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
