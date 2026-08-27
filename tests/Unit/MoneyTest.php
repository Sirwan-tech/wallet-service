<?php

namespace Tests\Unit;

use App\Domain\Money;
use App\Exceptions\CurrencyMismatchException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Money is a pure value object: these run with no Laravel application and no
 * database, which is the point - the money rules are testable in isolation.
 */
class MoneyTest extends TestCase
{
    public function test_it_is_created_from_integer_minor_units(): void
    {
        $money = Money::of(1250, 'USD');

        $this->assertSame(1250, $money->minorUnits);
        $this->assertIsInt($money->minorUnits);
        $this->assertSame('USD', $money->currency);
    }

    public function test_it_allows_a_zero_amount(): void
    {
        $this->assertSame(0, Money::of(0, 'USD')->minorUnits);
    }

    public function test_it_rejects_a_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount cannot be negative.');

        Money::of(-1, 'USD');
    }

    public function test_it_normalises_the_currency_code_to_uppercase(): void
    {
        $this->assertSame('USD', Money::of(100, 'usd')->currency);
    }

    #[DataProvider('invalidCurrencyCodes')]
    public function test_it_rejects_a_currency_code_that_is_not_three_letters(string $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency must be a 3-letter code.');

        Money::of(100, $code);
    }

    public static function invalidCurrencyCodes(): array
    {
        return [
            'too short' => ['US'],
            'too long'  => ['USDD'],
            'empty'     => [''],
        ];
    }

    public function test_it_adds_two_amounts_of_the_same_currency(): void
    {
        $sum = Money::of(100, 'USD')->add(Money::of(50, 'USD'));

        $this->assertSame(150, $sum->minorUnits);
        $this->assertSame('USD', $sum->currency);
    }

    public function test_it_subtracts_two_amounts_of_the_same_currency(): void
    {
        $this->assertSame(60, Money::of(100, 'USD')->subtract(Money::of(40, 'USD'))->minorUnits);
    }

    public function test_it_refuses_to_add_different_currencies(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::of(100, 'USD')->add(Money::of(50, 'EUR'));
    }

    public function test_it_refuses_to_subtract_different_currencies(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::of(100, 'USD')->subtract(Money::of(50, 'EUR'));
    }

    public function test_it_refuses_to_compare_different_currencies(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::of(100, 'USD')->isGreaterThan(Money::of(50, 'EUR'));
    }

    /**
     * This is the boundary that makes "withdraw your entire balance" legal:
     * WalletService rejects a debit only when it is STRICTLY greater than the
     * balance, so an equal amount must not be reported as greater.
     */
    public function test_greater_than_is_strict_so_an_equal_amount_is_not_greater(): void
    {
        $this->assertTrue(Money::of(150, 'USD')->isGreaterThan(Money::of(100, 'USD')));
        $this->assertFalse(Money::of(100, 'USD')->isGreaterThan(Money::of(100, 'USD')));
        $this->assertFalse(Money::of(50, 'USD')->isGreaterThan(Money::of(100, 'USD')));
    }

    public function test_it_is_immutable(): void
    {
        $original = Money::of(100, 'USD');
        $result = $original->add(Money::of(50, 'USD'));

        $this->assertNotSame($original, $result);
        $this->assertSame(100, $original->minorUnits);
        $this->assertSame(150, $result->minorUnits);
    }

    public function test_its_amount_cannot_be_reassigned(): void
    {
        $money = Money::of(100, 'USD');

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');

        $money->minorUnits = 5;
    }

    /**
     * 2^53 + 1 cannot be represented exactly as a float. If any part of the
     * money path silently went through a float this assertion would fail,
     * which is the whole reason amounts are integer minor units.
     */
    public function test_it_handles_amounts_beyond_float_precision(): void
    {
        $money = Money::of(9_007_199_254_740_993, 'USD');

        $this->assertSame(9_007_199_254_740_993, $money->minorUnits);
        $this->assertIsInt($money->minorUnits);
    }

    /**
     * KNOWN GAP (reported in TESTING.md): add() and subtract() build a Money
     * through the private constructor and therefore skip the non-negative
     * guard in of(). No negative balance can reach the database today because
     * WalletService checks the balance before subtracting, but the value
     * object does not defend the invariant on its own. This test pins the
     * current behaviour so that fixing it is a deliberate, visible change.
     */
    public function test_subtract_currently_produces_a_negative_money_bypassing_the_of_guard(): void
    {
        $result = Money::of(100, 'USD')->subtract(Money::of(150, 'USD'));

        $this->assertSame(-50, $result->minorUnits);
        $this->assertTrue($result->isNegative());
    }
}
