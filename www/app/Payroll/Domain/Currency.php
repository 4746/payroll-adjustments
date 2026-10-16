<?php

declare(strict_types=1);

namespace App\Payroll\Domain;

enum Currency: string
{
    case Usd = 'USD';
    // case Eur = 'EUR';
    // case Uah = 'UAH';

    // 1 dinar = 1000 fils; requires 3 decimal places, while Money currently uses 2.
    // case Kwd = 'KWD';

    // Bahraini and Jordanian dinars: 1000 fils; Tunisian dinar: 1000 millimes. All require 3 decimal places.
    // case Bhd = 'BHD';
    // case Jod = 'JOD';
    // case Tnd = 'TND';

    /**
     * Check membership in this enum, not support for currency conversion.
     * Strings are compared with backing values; enum instances with cases.
     * Every declared case is allowed, so a Currency instance always passes.
     * Other input types are rejected with false instead of a type error.
     */
    public static function isAllowed(mixed $value): bool
    {
        if (! is_string($value) && ! $value instanceof self) {
            return false;
        }

        if (is_string($value)) {
            return in_array($value, self::values(), true);
        }

        return in_array($value, self::cases(), true);
    }

    /**
     * Return currency codes as strings rather than enum instances.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
