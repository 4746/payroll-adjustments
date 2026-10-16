<?php

declare(strict_types=1);

namespace App\Payroll\Domain;

use InvalidArgumentException;
use OverflowException;

/**
 * An exact amount in a currency with two fractional digits, stored in signed minor units.
 * Arithmetic returns new values and rejects overflow rather than producing floats.
 */
final readonly class Money
{
    private function __construct(public int $minorUnits, public Currency $currency) {}

    public static function fromMinorUnits(int $minorUnits, Currency $currency = Currency::Usd): self
    {
        return new self(minorUnits: $minorUnits, currency: $currency);
    }

    /**
     * Parse decimal text without rounding or floating-point conversion.
     * Build the signed cents as text before validating integer capacity; this
     * also supports PHP_INT_MIN, whose positive magnitude cannot fit in an int.
     *
     * @throws InvalidArgumentException If input is inexact, malformed or out of range.
     */
    public static function fromDecimal(string $amount, Currency $currency = Currency::Usd): self
    {
        if (preg_match('/^([+-]?)(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D', $amount, $parts) !== 1) {
            throw new InvalidArgumentException(message: 'Use a '.$currency->value.' decimal with at most two fractional digits.');
        }

        $digits = ltrim($parts[2].str_pad($parts[3] ?? '', 2, '0'), '0');
        $minorUnits = filter_var(($parts[1] === '-' && $digits !== '' ? '-' : '').($digits === '' ? '0' : $digits), FILTER_VALIDATE_INT);

        if ($minorUnits === false) {
            throw new InvalidArgumentException(message: $currency->value.' amount exceeds the integer minor-unit range.');
        }

        return new self(minorUnits: $minorUnits, currency: $currency);
    }

    /**
     * Check bounds before addition, since PHP promotes overflowing integers to floats.
     *
     * @throws OverflowException
     */
    public function add(self $other): self
    {
        $this->ensureSameCurrency(other: $other);

        if (($other->minorUnits > 0 && $this->minorUnits > PHP_INT_MAX - $other->minorUnits)
            || ($other->minorUnits < 0 && $this->minorUnits < PHP_INT_MIN - $other->minorUnits)) {
            throw new OverflowException(message: 'Money addition exceeds the integer minor-unit range.');
        }

        return new self(minorUnits: $this->minorUnits + $other->minorUnits, currency: $this->currency);
    }

    /**
     * Subtract directly instead of negating the operand: -PHP_INT_MIN overflows.
     *
     * @throws OverflowException
     */
    public function subtract(self $other): self
    {
        $this->ensureSameCurrency(other: $other);

        if (($other->minorUnits > 0 && $this->minorUnits < PHP_INT_MIN + $other->minorUnits)
            || ($other->minorUnits < 0 && $this->minorUnits > PHP_INT_MAX + $other->minorUnits)) {
            throw new OverflowException(message: 'Money subtraction exceeds the integer minor-unit range.');
        }

        return new self(minorUnits: $this->minorUnits - $other->minorUnits, currency: $this->currency);
    }

    private function ensureSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidAdjustment(message: 'Amounts must use the same currency.');
        }
    }

    /**
     * Canonical decimal text with two fractional digits, including at PHP_INT_MIN.
     * Only the quotient and remainder are made positive, never the full amount.
     */
    public function toDecimal(): string
    {
        return ($this->minorUnits < 0 ? '-' : '').abs(intdiv($this->minorUnits, 100)).'.'.str_pad((string) abs($this->minorUnits % 100), 2, '0', STR_PAD_LEFT);
    }
}
