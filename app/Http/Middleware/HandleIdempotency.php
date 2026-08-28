<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Makes every money operation safe to retry.
 *
 * The idempotency reservation, wallet mutation, ledger rows, and saved HTTP
 * response share one outer database transaction. A concurrent request using
 * the same key waits on the unique index, then replays the completed result
 * instead of applying the operation again.
 */
final class HandleIdempotency
{
    private const PROCESSING_STATUS = 102;

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '') {
            return $this->error(
                'idempotency_key_required',
                'The Idempotency-Key header is required for this operation.',
                400,
            );
        }

        // Bound the value before it reaches the database/cache. UUIDs and the
        // usual client-generated keys are accepted without exposing a free-form
        // header as a storage or log amplification vector.
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/', $key)) {
            return $this->error(
                'idempotency_key_invalid',
                'The Idempotency-Key header must be 8 to 255 safe ASCII characters.',
                400,
            );
        }

        $requestHash = $this->requestHash($request);

        return DB::transaction(function () use ($request, $next, $key, $requestHash): Response {
            try {
                // This insert is intentionally first. The unique index is the
                // serialization point that closes the old check-then-insert race.
                $record = IdempotencyKey::create([
                    'idempotency_key' => $key,
                    'request_hash' => $requestHash,
                    'response_status' => self::PROCESSING_STATUS,
                    'response_body' => [],
                ]);
            } catch (QueryException $exception) {
                if (!$this->isDuplicateKeyException($exception)) {
                    throw $exception;
                }

                $record = IdempotencyKey::where('idempotency_key', $key)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (!hash_equals((string) $record->request_hash, $requestHash)) {
                    return $this->error(
                        'idempotency_conflict',
                        'This Idempotency-Key was already used with a different request.',
                        409,
                    );
                }

                // A row can only remain in this state if an older deployment
                // crashed after committing the reservation. New requests never
                // commit it until the final response has been saved.
                if ((int) $record->response_status === self::PROCESSING_STATUS) {
                    return $this->error(
                        'idempotency_in_progress',
                        'A request with this Idempotency-Key is still being processed.',
                        409,
                    );
                }

                return response()->json(
                    $record->response_body,
                    (int) $record->response_status,
                );
            }

            try {
                $response = $next($request);
            } catch (Throwable $exception) {
                // Client errors are terminal outcomes: record and replay them
                // too, so a key cannot later be reused with a changed payload.
                $response = app(ExceptionHandler::class)->render($request, $exception);

                // A server failure is deliberately not cached. Re-throwing
                // rolls back both the reservation and any wallet mutation.
                if ($response->getStatusCode() >= 500) {
                    throw $exception;
                }
            }

            if ($response->getStatusCode() >= 500) {
                // Never commit money without a replayable response. A 5xx
                // response rolls the outer transaction back and can be retried.
                throw new \RuntimeException('A money operation returned an unexpected server error.');
            }

            $record->update([
                'response_status' => $response->getStatusCode(),
                'response_body' => $this->responseBody($response),
            ]);

            return $response;
        }, attempts: 3);
    }

    private function requestHash(Request $request): string
    {
        $principal = (string) ($request->user()?->getAuthIdentifier() ?? 'anonymous');

        return hash('sha256', implode('|', [
            $principal,
            $request->method(),
            $request->path(),
            $request->getContent(),
        ]));
    }

    /** @return array<string, mixed> */
    private function responseBody(Response $response): array
    {
        $body = json_decode($response->getContent(), true);

        if (!is_array($body)) {
            throw new \RuntimeException('Idempotent API responses must contain a JSON object.');
        }

        return $body;
    }

    private function isDuplicateKeyException(QueryException $exception): bool
    {
        return (string) $exception->getCode() === '23000'
            && (($exception->errorInfo[1] ?? null) === 1062);
    }

    private function error(string $code, string $message, int $status): Response
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
