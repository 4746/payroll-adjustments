<?php

declare(strict_types=1);

namespace Tests\Unit\Payroll\Domain;

use App\Payroll\Domain\Currency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CurrencyTest extends TestCase
{
    #[DataProvider('currencyValues')]
    public function test_currency_membership_accepts_known_values_and_rejects_invalid_input(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, Currency::isAllowed($value));
    }

    /** @return array<string, array{mixed, bool}> */
    public static function currencyValues(): array
    {
        return [
            'known code' => ['USD', true],
            'known case' => [Currency::Usd, true],
            'unknown code' => ['XYZ', false],
            'lowercase code' => ['usd', false],
            'empty code' => ['', false],
            'null' => [null, false],
            'integer' => [1, false],
            'float' => [1.5, false],
            'boolean' => [true, false],
            'array' => [['USD'], false],
            'object' => [(object) ['value' => 'USD'], false],
        ];
    }
}
