<?php

namespace App\Domain;

use App\Exceptions\CurrencyMismatchException;
use App\Exceptions\InsufficientFundsException;
use InvalidArgumentException;

/**
 * Pure balance decisions for wallet operations.
 *
 * This class deliberately has no Laravel, database, configuration, or model
 * dependency. WalletService supplies locking and persistence; these methods
 * decide the next immutable Money values.
 */
final class BalanceRules
{
    public function deposit(Money $balance, int $amountMinor): Money
    {
        return $balance->add($this->amountFor($balance, $amountMinor));
    }

    public function withdraw(Money $balance, int $amountMinor): Money
    {
        $amount = $this->amountFor($balance, $amountMinor);

        if ($amount->isGreaterThan($balance)) {
            throw new InsufficientFundsException;
        }

        return $balance->subtract($amount);
    }

    /**
     * @return array{from: Money, to: Money}
     */
    public function transfer(
        string $fromAccountId,
        Money $fromBalance,
        string $toAccountId,
        Money $toBalance,
        int $amountMinor,
    ): array {
        // Validate the amount before the rest of the operation, matching the
        // service boundary and ensuring zero-value movements never exist.
        $amount = $this->amountFor($fromBalance, $amountMinor);

        $this->assertDifferentAccounts($fromAccountId, $toAccountId);
        $this->assertSameCurrency($fromBalance, $toBalance);

        if ($amount->isGreaterThan($fromBalance)) {
            throw new InsufficientFundsException;
        }

        return [
            'from' => $fromBalance->subtract($amount),
            'to' => $toBalance->add($amount),
        ];
    }

    public function assertDifferentAccounts(string $fromAccountId, string $toAccountId): void
    {
        if ($fromAccountId === $toAccountId) {
            throw new InvalidArgumentException('Cannot transfer to the same account.');
        }
    }

    private function amountFor(Money $balance, int $amountMinor): Money
    {
        if ($amountMinor < 1) {
            throw new InvalidArgumentException('Amount must be at least 1 minor unit.');
        }

        return Money::of($amountMinor, $balance->currency);
    }

    private function assertSameCurrency(Money $from, Money $to): void
    {
        if ($from->currency !== $to->currency) {
            throw new CurrencyMismatchException($from->currency, $to->currency);
        }
    }
}
