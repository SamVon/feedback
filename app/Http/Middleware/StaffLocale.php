<?php

namespace App\Http\Middleware;

use Closure;

class StaffLocale
{
    public function handle($request, Closure $next)
    {
        $locale = session('feedback_locale', config('feedback.locale'));
        app()->setLocale(in_array($locale, ['en', 'lo'], true) ? $locale : 'lo');

        return $next($request);
    }
}
