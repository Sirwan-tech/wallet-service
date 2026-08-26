<?php

namespace App\Services;

use App\Domain\Money;
use App\Exceptions\AccountFrozenException;
use App\Exceptions\CurrencyMismatchException;
use App\Exceptions\InsufficientFundsException;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletService
{
    /**
     * Deposit money into an account.
     */
    public function deposit(string $accountId, int $amount): Transaction
    {
        return DB::transaction(function () use ($accountId, $amount) {
            // Lock the account row so concurrent requests wait (rule 7)
            $account = Account::whereKey($accountId)->lockForUpdate()->firstOrFail();

            $this->assertActive($account);

            $money = Money::of($account->balance, $account->currency)
                ->add(Money::of($amount, $account->currency));

            $account->balance = $money->minorUnits;
            $account->save();

            return $this->record($account, 'deposit', $amount, $account->balance);
        });
    }

    /**
     * Withdraw money from an account.
     */
    public function withdraw(string $accountId, int $amount): Transaction
    {
        return DB::transaction(function () use ($accountId, $amount) {
            $account = Account::whereKey($accountId)->lockForUpdate()->firstOrFail();

            $this->assertActive($account);

            $balance = Money::of($account->balance, $account->currency);
            $debit = Money::of($amount, $account->currency);

            // Balance must never go negative (rule 1)
            if ($debit->isGreaterThan($balance)) {
                throw new InsufficientFundsException();
            }

            $account->balance = $balance->subtract($debit)->minorUnits;
            $account->save();

            return $this->record($account, 'withdrawal', $amount, $account->balance);
        });
    }

    /**
     * Transfer money between two accounts. Atomic: both legs or neither (rule 2).
     */
    public function transfer(string $fromId, string $toId, int $amount): array
    {
        if ($fromId === $toId) {
            throw new \InvalidArgumentException('Cannot transfer to the same account.');
        }

        return DB::transaction(function () use ($fromId, $toId, $amount) {
            // Lock both accounts in a consistent order to avoid deadlocks
            $ids = [$fromId, $toId];
            sort($ids);
            $locked = Account::whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

            $from = $locked->get($fromId);
            $to = $locked->get($toId);

            if (!$from || !$to) {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
            }

            $this->assertActive($from);
            $this->assertActive($to);

            // Same currency only (rule 3)
            if ($from->currency !== $to->currency) {
                throw new CurrencyMismatchException($from->currency, $to->currency);
            }

            $fromBalance = Money::of($from->balance, $from->currency);
            $debit = Money::of($amount, $from->currency);

            if ($debit->isGreaterThan($fromBalance)) {
                throw new InsufficientFundsException();
            }

            // Both legs share one transfer_id so you can see they belong together
            $transferId = (string) Str::uuid();

            $from->balance = $fromBalance->subtract($debit)->minorUnits;
            $from->save();
            $out = $this->record($from, 'transfer_out', $amount, $from->balance, $transferId);

            $to->balance = Money::of($to->balance, $to->currency)->add($debit)->minorUnits;
            $to->save();
            $in = $this->record($to, 'transfer_in', $amount, $to->balance, $transferId);

            return ['out' => $out, 'in' => $in, 'transfer_id' => $transferId];
        });
    }

    private function assertActive(Account $account): void
    {
        if ($account->isFrozen()) {
            throw new AccountFrozenException();
        }
    }

    private function record(
        Account $account,
        string $type,
        int $amount,
        int $balanceAfter,
        ?string $transferId = null
    ): Transaction {
        return Transaction::create([
            'account_id' => $account->id,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'transfer_id' => $transferId,
        ]);
    }
}
