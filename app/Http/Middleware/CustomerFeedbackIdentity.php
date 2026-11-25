<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CustomerFeedbackIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(strlen($request->getContent()) > 2097152, 413);
        $secret = config('feedback.customer_jwt_secret');
        abort_unless(is_string($secret) && $secret !== '', 503, 'Customer authentication is not configured.');
        $token = $request->bearerToken();
        $parts = is_string($token) && strlen($token) <= 8192 ? explode('.', $token) : [];
        abort_unless(count($parts) === 3, 401, 'Invalid customer token.');
        [$headerPart, $payloadPart, $signature] = $parts;
        $decode = static function (string $value): mixed {
            if ($value === '' || ! preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
                return null;
            }
            $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

            return $decoded === false ? null : json_decode($decoded, true);
        };
        $header = $decode($headerPart);
        $claims = $decode($payloadPart);
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $headerPart.'.'.$payloadPart, $secret, true)), '+/', '-_'), '=');
        abort_unless(is_array($header) && ($header['alg'] ?? null) === 'HS256' && is_array($claims) && hash_equals($expected, $signature), 401, 'Invalid customer token.');
        abort_unless(isset($claims['exp']) && is_numeric($claims['exp']) && $claims['exp'] > time() && (! isset($claims['nbf']) || (is_numeric($claims['nbf']) && $claims['nbf'] <= time())), 401, 'Expired or invalid customer token.');
        abort_unless(is_string($claims['_id'] ?? null) && trim($claims['_id']) !== '' && strlen($claims['_id']) <= 100, 401, 'Invalid customer token.');
        abort_unless(($claims['role'] ?? null) === 'CUSTOMER', 403, 'Customer role required.');
        $request->attributes->set('customer_actor', $claims['_id']);
        $names = array_filter([$claims['first_name'] ?? null, $claims['last_name'] ?? null], static fn ($value): bool => is_string($value) && trim($value) !== '');
        $name = implode(' ', array_map('trim', $names));
        $phone = $claims['phone'] ?? null;
        $request->attributes->set('customer_profile', [
            'name' => $name !== '' && mb_strlen($name) <= 200 ? $name : null,
            'phone' => is_string($phone) && strlen($phone) <= 40 && preg_match('/^\+?[0-9][0-9 ().-]{3,38}[0-9]$/D', $phone) ? $phone : null,
        ]);

        return $next($request);
    }
}
