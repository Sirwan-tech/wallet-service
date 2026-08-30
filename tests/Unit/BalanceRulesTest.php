<?php

namespace Tests\Unit;

use App\Domain\BalanceRules;
use App\Domain\Money;
use App\Exceptions\CurrencyMismatchException;
use App\Exceptions\InsufficientFundsException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * These tests use only immutable Money values and the pure BalanceRules class.
 * They boot neither Laravel nor a database; persistence and locking remain
 * covered by the real-MySQL feature and concurrency tests.
 */
class BalanceRulesTest extends TestCase
{
    private BalanceRules $rules;

    protected function setUp(): void
    {
        $this->rules = new BalanceRules;
    }

    public function test_a_deposit_returns_an_increased_balance_without_mutating_the_original(): void
    {
        $balance = Money::of(100, 'USD');

        $result = $this->rules->deposit($balance, 25);

        $this->assertSame(125, $result->minorUnits);
        $this->assertSame('USD', $result->currency);
        $this->assertSame(100, $balance->minorUnits);
        $this->assertNotSame($balance, $result);
    }

    public function test_a_deposit_rejects_a_zero_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be at least 1 minor unit.');

        $this->rules->deposit(Money::of(100, 'USD'), 0);
    }

    public function test_a_withdrawal_debits_the_balance(): void
    {
        $result = $this->rules->withdraw(Money::of(100, 'USD'), 40);

        $this->assertSame(60, $result->minorUnits);
        $this->assertSame('USD', $result->currency);
    }

    public function test_a_withdrawal_of_the_entire_balance_is_allowed(): void
    {
        $result = $this->rules->withdraw(Money::of(100, 'USD'), 100);

        $this->assertSame(0, $result->minorUnits);
    }

    public function test_a_withdrawal_one_minor_unit_over_the_balance_is_rejected_without_mutating_it(): void
    {
        $balance = Money::of(100, 'USD');

        try {
            $this->rules->withdraw($balance, 101);
            $this->fail('Expected InsufficientFundsException was not thrown.');
        } catch (InsufficientFundsException) {
            $this->assertSame(100, $balance->minorUnits);
        }
    }

    public function test_a_withdrawal_from_a_zero_balance_is_rejected(): void
    {
        $this->expectException(InsufficientFundsException::class);

        $this->rules->withdraw(Money::of(0, 'USD'), 1);
    }

    public function test_a_withdrawal_rejects_a_zero_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->rules->withdraw(Money::of(100, 'USD'), 0);
    }

    public function test_a_transfer_moves_money_and_conserves_the_total(): void
    {
        $from = Money::of(100, 'USD');
        $to = Money::of(20, 'USD');

        $result = $this->rules->transfer('from', $from, 'to', $to, 40);

        $this->assertSame(60, $result['from']->minorUnits);
        $this->assertSame(60, $result['to']->minorUnits);
        $this->assertSame(120, $result['from']->minorUnits + $result['to']->minorUnits);
        $this->assertSame(100, $from->minorUnits);
        $this->assertSame(20, $to->minorUnits);
    }

    public function test_a_transfer_of_the_entire_balance_is_allowed(): void
    {
        $result = $this->rules->transfer(
            'from',
            Money::of(100, 'USD'),
            'to',
            Money::of(20, 'USD'),
            100,
        );

        $this->assertSame(0, $result['from']->minorUnits);
        $this->assertSame(120, $result['to']->minorUnits);
    }

    public function test_an_overdrawing_transfer_leaves_both_input_balances_unchanged(): void
    {
        $from = Money::of(100, 'USD');
        $to = Money::of(20, 'USD');

        try {
            $this->rules->transfer('from', $from, 'to', $to, 101);
            $this->fail('Expected InsufficientFundsException was not thrown.');
        } catch (InsufficientFundsException) {
            $this->assertSame(100, $from->minorUnits);
            $this->assertSame(20, $to->minorUnits);
        }
    }

    public function test_a_transfer_between_different_currencies_is_rejected_without_mutating_inputs(): void
    {
        $from = Money::of(100, 'USD');
        $to = Money::of(20, 'EUR');

        try {
            $this->rules->transfer('from', $from, 'to', $to, 40);
            $this->fail('Expected CurrencyMismatchException was not thrown.');
        } catch (CurrencyMismatchException) {
            $this->assertSame(100, $from->minorUnits);
            $this->assertSame(20, $to->minorUnits);
        }
    }

    public function test_a_transfer_to_the_same_account_is_rejected_before_any_movement(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot transfer to the same account.');

        $this->rules->transfer(
            'account',
            Money::of(100, 'USD'),
            'account',
            Money::of(20, 'USD'),
            40,
        );
    }

    public function test_a_transfer_rejects_a_zero_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->rules->transfer(
            'from',
            Money::of(100, 'USD'),
            'to',
            Money::of(20, 'USD'),
            0,
        );
    }
}
