<?php

declare(strict_types=1);

namespace App\Payroll\Domain;

/** Single payroll ID rule shared by domain objects, HTTP validation and routing. */
final class PayrollIdValidator
{
    /** Route constraints use the pattern without regex delimiters or anchors. */
    public const string PATTERN = '[A-Za-z0-9_-]{1,128}';

    /** Input validation checks the entire string using the same pattern. */
    public const string REGEX = '/^'.self::PATTERN.'$/D';

    private function __construct() {}

    /** Accept 1-128 ASCII letters, digits, underscores or hyphens. */
    public static function isValid(string $value): bool
    {
        return preg_match(self::REGEX, $value) === 1;
    }
}
