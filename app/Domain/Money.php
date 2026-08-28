<?php

namespace App\Domain;

use App\Exceptions\CurrencyMismatchException;
use InvalidArgumentException;

final class Money
{
    private function __construct(
        public readonly int $minorUnits,   // amount in cents
        public readonly string $currency,  // 3-letter code, uppercase
    ) {}

    public static function of(int $minorUnits, string $currency): self
    {
        if ($minorUnits < 0) {
            throw new InvalidArgumentException('Amount cannot be negative.');
        }
        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException('Currency must be a 3-letter code.');
        }
        return new self($minorUnits, strtoupper($currency));
    }

    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);

        if ($other->minorUnits > PHP_INT_MAX - $this->minorUnits) {
            throw new InvalidArgumentException('Amount exceeds the supported range.');
        }

        return self::of($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function subtract(Money $other): self
    {
        $this->assertSameCurrency($other);

        if ($other->minorUnits > $this->minorUnits) {
            throw new InvalidArgumentException('Amount cannot be negative.');
        }

        return self::of($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function isGreaterThan(Money $other): bool
    {
        $this->assertSameCurrency($other);
        return $this->minorUnits > $other->minorUnits;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new CurrencyMismatchException($this->currency, $other->currency);
        }
    }
}
