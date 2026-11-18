<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class ServiceIdentity
{
    public function handle(Request $request, Closure $next, string $scope)
    {
        abort_if(strlen($request->getContent()) > 2097152, 413, 'Request too large.');
        $caller = $request->header('X-Service-ID', '');
        $identity = config('feedback.services.'.$caller);
        $token = $request->bearerToken();
        abort_unless($token && $identity && ! empty($identity['token_hash']) && hash_equals($identity['token_hash'], hash('sha256', $token)), 401, 'Invalid service identity.');
        abort_unless(in_array($scope, $identity['scopes'], true), 403, 'Service scope denied.');
        $request->attributes->set('service_caller', $caller);

        return $next($request);
    }
}
