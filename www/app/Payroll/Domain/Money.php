<?php

declare(strict_types=1);

namespace App\Payroll\Domain;

use InvalidArgumentException;
use OverflowException;

final readonly class Money
{
    private function __construct(public int $minorUnits) {}

    public static function fromMinorUnits(int $minorUnits): self
    {
        return new self($minorUnits);
    }

    public static function fromDecimal(string $amount): self
    {
        if (preg_match('/^([+-]?)(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D', $amount, $parts) !== 1) {
            throw new InvalidArgumentException('Use a USD decimal with at most two fractional digits.');
        }

        $digits = ltrim($parts[2].str_pad($parts[3] ?? '', 2, '0'), '0');
        $minorUnits = filter_var(($parts[1] === '-' && $digits !== '' ? '-' : '').($digits === '' ? '0' : $digits), FILTER_VALIDATE_INT);

        if ($minorUnits === false) {
            throw new InvalidArgumentException('USD amount exceeds the integer minor-unit range.');
        }

        return new self($minorUnits);
    }

    public function add(self $other): self
    {
        if (($other->minorUnits > 0 && $this->minorUnits > PHP_INT_MAX - $other->minorUnits)
            || ($other->minorUnits < 0 && $this->minorUnits < PHP_INT_MIN - $other->minorUnits)) {
            throw new OverflowException('Money addition exceeds the integer minor-unit range.');
        }

        return new self($this->minorUnits + $other->minorUnits);
    }

    public function subtract(self $other): self
    {
        if (($other->minorUnits > 0 && $this->minorUnits < PHP_INT_MIN + $other->minorUnits)
            || ($other->minorUnits < 0 && $this->minorUnits > PHP_INT_MAX + $other->minorUnits)) {
            throw new OverflowException('Money subtraction exceeds the integer minor-unit range.');
        }

        return new self($this->minorUnits - $other->minorUnits);
    }

    public function toDecimal(): string
    {
        return ($this->minorUnits < 0 ? '-' : '').abs(intdiv($this->minorUnits, 100)).'.'.str_pad((string) abs($this->minorUnits % 100), 2, '0', STR_PAD_LEFT);
    }
}
