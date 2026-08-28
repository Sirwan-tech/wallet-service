<?php

/**
 * Runs ONE wallet operation in its own OS process, so the concurrency tests
 * can put several of them against the same account at the same instant. A
 * single PHP process cannot exercise row locking against itself.
 *
 * Usage:
 *   php tests/Support/concurrent_operation.php <deposit|withdraw> <accountId> <amount> <startAtMicroseconds>
 *
 * Exit codes: 0 applied, 3 rejected by a business rule, 9 unexpected error.
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[, $action, $accountId, $amount, $startAt] = $argv;

// The parent starts the children a few milliseconds apart; they wait here so
// that they hit the account together rather than in sequence.
while (microtime(true) * 1_000_000 < (int) $startAt) {
    usleep(100);
}

try {
    $wallet = app(App\Services\WalletService::class);

    $action === 'withdraw'
        ? $wallet->withdraw($accountId, (int) $amount)
        : $wallet->deposit($accountId, (int) $amount);

    echo 'APPLIED';
    exit(0);
} catch (App\Exceptions\InsufficientFundsException) {
    echo 'REJECTED';
    exit(3);
} catch (Throwable $e) {
    echo 'ERROR: ' . get_class($e) . ': ' . $e->getMessage();
    exit(9);
}
