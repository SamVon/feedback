<?php

namespace App\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class InternalErrors
{
    public static function render(\Throwable $error, Request $request)
    {
        if (! $request->is('internal/*')) {
            return null;
        }
        $status = $error instanceof ValidationException ? 422 : ($error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500);
        $code = match ($status) {
            401 => 'UNAUTHENTICATED',403 => 'FORBIDDEN',404 => 'NOT_FOUND',409 => 'CONFLICT',413 => 'PAYLOAD_TOO_LARGE',422 => 'VALIDATION_FAILED',429 => 'RATE_LIMITED',503 => 'UNAVAILABLE',default => 'INTERNAL_ERROR'
        };
        $requestId = (string) Str::uuid();
        $body = ['error' => ['code' => $code, 'message' => $status >= 500 ? 'Service unavailable. Retry with the same idempotency key.' : $error->getMessage(), 'request_id' => $requestId]];
        if ($error instanceof ValidationException) {
            $body['error']['fields'] = $error->errors();
        }

        return response()->json($body, $status, $error instanceof HttpExceptionInterface ? $error->getHeaders() : [])->header('X-Request-ID', $requestId);
    }
}
