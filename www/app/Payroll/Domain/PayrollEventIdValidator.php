<?php

declare(strict_types=1);

namespace App\Payroll\Domain;

/** Shared format validation for payroll event identities, including restored events. */
final class PayrollEventIdValidator
{
    public const string PATTERN = '[a-f0-9]{32}';

    public const string REGEX = '/^'.self::PATTERN.'$/D';

    private function __construct() {}

    /** Accept exactly 32 lowercase hexadecimal characters generated from 16 random bytes. */
    public static function isValid(string $value): bool
    {
        return preg_match(self::REGEX, $value) === 1;
    }
}
