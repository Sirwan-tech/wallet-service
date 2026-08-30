<?php

namespace App\Services;

use App\Domain\BalanceRules;
use App\Domain\Money;
use App\Exceptions\AccountFrozenException;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletService
{
    public function __construct(private BalanceRules $rules) {}

    /** Credit an account and record the operation atomically. */
    public function deposit(string $accountId, int $amount): Transaction
    {
        $this->assertValidAmount($amount);

        return DB::transaction(function () use ($accountId, $amount) {
            $account = Account::whereKey($accountId)->lockForUpdate()->firstOrFail();

            $this->assertActive($account);

            $money = $this->rules->deposit(
                Money::of($account->balance, $account->currency),
                $amount,
            );

            $account->balance = $money->minorUnits;
            $account->save();

            return $this->record($account, 'deposit', $amount, $account->balance);
        }, attempts: 3);
    }

    /** Debit an account without allowing a negative balance. */
    public function withdraw(string $accountId, int $amount): Transaction
    {
        $this->assertValidAmount($amount);

        return DB::transaction(function () use ($accountId, $amount) {
            $account = Account::whereKey($accountId)->lockForUpdate()->firstOrFail();

            $this->assertActive($account);

            $account->balance = $this->rules->withdraw(
                Money::of($account->balance, $account->currency),
                $amount,
            )->minorUnits;
            $account->save();

            return $this->record($account, 'withdrawal', $amount, $account->balance);
        }, attempts: 3);
    }

    /**
     * Move money between accounts. Both balance changes and both ledger legs
     * either commit together or roll back together.
     *
     * @return array{out: Transaction, in: Transaction, transfer_id: string}
     */
    public function transfer(string $fromId, string $toId, int $amount): array
    {
        $this->assertValidAmount($amount);

        $this->rules->assertDifferentAccounts($fromId, $toId);

        return DB::transaction(function () use ($fromId, $toId, $amount) {
            // Acquire locks one row at a time in one explicit order. A single
            // whereIn query does not promise that MySQL will lock in that order.
            $ids = [$fromId, $toId];
            sort($ids, SORT_STRING);

            $locked = [];
            foreach ($ids as $id) {
                $account = Account::whereKey($id)->lockForUpdate()->first();

                if (! $account) {
                    throw new ModelNotFoundException;
                }

                $locked[$id] = $account;
            }

            $from = $locked[$fromId];
            $to = $locked[$toId];

            $this->assertActive($from);
            $this->assertActive($to);

            $balances = $this->rules->transfer(
                $fromId,
                Money::of($from->balance, $from->currency),
                $toId,
                Money::of($to->balance, $to->currency),
                $amount,
            );

            $transferId = (string) Str::uuid();

            $from->balance = $balances['from']->minorUnits;
            $from->save();
            $out = $this->record($from, 'transfer_out', $amount, $from->balance, $transferId);

            $to->balance = $balances['to']->minorUnits;
            $to->save();
            $in = $this->record($to, 'transfer_in', $amount, $to->balance, $transferId);

            return ['out' => $out, 'in' => $in, 'transfer_id' => $transferId];
        }, attempts: 3);
    }

    private function assertActive(Account $account): void
    {
        if ($account->isFrozen()) {
            throw new AccountFrozenException;
        }
    }

    private function assertValidAmount(int $amount): void
    {
        $maximum = (int) config('wallet.max_amount_minor');

        if ($amount < 1 || $amount > $maximum) {
            throw new \InvalidArgumentException("Amount must be between 1 and {$maximum} minor units.");
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
