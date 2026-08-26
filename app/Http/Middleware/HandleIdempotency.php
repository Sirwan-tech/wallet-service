<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        // No key provided → require it for money-moving operations
        if (!$key) {
            return response()->json([
                'error' => [
                    'code' => 'idempotency_key_required',
                    'message' => 'The Idempotency-Key header is required for this operation.',
                ],
            ], 400);
        }

        // Hash the request body so we can detect "same key, different payload"
        $requestHash = hash('sha256', $request->getContent());

        // Look for an existing record with this key
        $existing = IdempotencyKey::where('idempotency_key', $key)->first();

        if ($existing) {
            // Same key + same payload → return the original saved response
            if ($existing->request_hash === $requestHash) {
                return response()->json(
                    $existing->response_body,
                    $existing->response_status
                );
            }

            // Same key + DIFFERENT payload → conflict
            return response()->json([
                'error' => [
                    'code' => 'idempotency_conflict',
                    'message' => 'This Idempotency-Key was already used with a different request.',
                ],
            ], 409);
        }

        // First time seeing this key → process the request
        $response = $next($request);

        // Only store successful (2xx) responses
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            try {
                IdempotencyKey::create([
                    'idempotency_key' => $key,
                    'request_hash' => $requestHash,
                    'response_status' => $response->getStatusCode(),
                    'response_body' => json_decode($response->getContent(), true),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Duplicate key inserted concurrently — safe to ignore
            }
        }

        return $response;
    }
}
