<?php

declare(strict_types=1);

namespace App\Payroll\Domain;

use InvalidArgumentException;

/** Validate decimal amounts and convert them to exact integer minor units. */
final class MoneyAmountParser
{
    public const string PATTERN = '([+-]?)(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?';

    public const string REGEX = '/^'.self::PATTERN.'$/D';

    private function __construct() {}

    /**
     * Build signed minor units as text to avoid floats and support PHP_INT_MIN.
     *
     * @throws InvalidArgumentException If input is malformed, inexact or out of range.
     */
    public static function parse(string $amount, Currency $currency = Currency::Usd): int
    {
        if (preg_match(self::REGEX, $amount, $parts) !== 1) {
            throw new InvalidArgumentException(message: 'Use a '.$currency->value.' decimal with at most two fractional digits.');
        }

        $digits = ltrim($parts[2].str_pad($parts[3] ?? '', 2, '0'), '0');
        $minorUnits = filter_var(($parts[1] === '-' && $digits !== '' ? '-' : '').($digits === '' ? '0' : $digits), FILTER_VALIDATE_INT);

        if ($minorUnits === false) {
            throw new InvalidArgumentException(message: $currency->value.' amount exceeds the integer minor-unit range.');
        }

        return $minorUnits;
    }
}
