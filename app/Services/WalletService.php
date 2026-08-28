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
    /** Credit an account and record the operation atomically. */
    public function deposit(string $accountId, int $amount): Transaction
    {
        $this->assertValidAmount($amount);

        return DB::transaction(function () use ($accountId, $amount) {
            $account = Account::whereKey($accountId)->lockForUpdate()->firstOrFail();

            $this->assertActive($account);

            $money = Money::of($account->balance, $account->currency)
                ->add(Money::of($amount, $account->currency));

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

            $balance = Money::of($account->balance, $account->currency);
            $debit = Money::of($amount, $account->currency);

            if ($debit->isGreaterThan($balance)) {
                throw new InsufficientFundsException();
            }

            $account->balance = $balance->subtract($debit)->minorUnits;
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

        if ($fromId === $toId) {
            throw new \InvalidArgumentException('Cannot transfer to the same account.');
        }

        return DB::transaction(function () use ($fromId, $toId, $amount) {
            // Acquire locks one row at a time in one explicit order. A single
            // whereIn query does not promise that MySQL will lock in that order.
            $ids = [$fromId, $toId];
            sort($ids, SORT_STRING);

            $locked = [];
            foreach ($ids as $id) {
                $account = Account::whereKey($id)->lockForUpdate()->first();

                if (!$account) {
                    throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
                }

                $locked[$id] = $account;
            }

            $from = $locked[$fromId];
            $to = $locked[$toId];

            $this->assertActive($from);
            $this->assertActive($to);

            if ($from->currency !== $to->currency) {
                throw new CurrencyMismatchException($from->currency, $to->currency);
            }

            $fromBalance = Money::of($from->balance, $from->currency);
            $debit = Money::of($amount, $from->currency);

            if ($debit->isGreaterThan($fromBalance)) {
                throw new InsufficientFundsException();
            }

            $transferId = (string) Str::uuid();

            $from->balance = $fromBalance->subtract($debit)->minorUnits;
            $from->save();
            $out = $this->record($from, 'transfer_out', $amount, $from->balance, $transferId);

            $to->balance = Money::of($to->balance, $to->currency)->add($debit)->minorUnits;
            $to->save();
            $in = $this->record($to, 'transfer_in', $amount, $to->balance, $transferId);

            return ['out' => $out, 'in' => $in, 'transfer_id' => $transferId];
        }, attempts: 3);
    }

    private function assertActive(Account $account): void
    {
        if ($account->isFrozen()) {
            throw new AccountFrozenException();
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
