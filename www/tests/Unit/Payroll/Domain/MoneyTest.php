<?php

declare(strict_types=1);

namespace Tests\Unit\Payroll\Domain;

use App\Payroll\Domain\Money;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_arithmetic_is_exact_and_values_are_immutable(): void
    {
        $money = Money::fromDecimal('0.10');
        $this->assertSame('0.30', $money->add(Money::fromDecimal('0.20'))->toDecimal());
        $this->assertSame('-0.10', $money->subtract(Money::fromDecimal('0.20'))->toDecimal());
        $this->assertSame('0.10', $money->toDecimal());
        $this->assertSame(10, $money->minorUnits);
    }

    #[DataProvider('validAmounts')]
    public function test_decimal_serialization_round_trips(string $input, string $expected): void
    {
        $this->assertSame($expected, Money::fromDecimal($input)->toDecimal());
        $this->assertSame($expected, Money::fromDecimal($expected)->toDecimal());
    }

    public static function validAmounts(): array
    {
        return [['1000', '1000.00'], ['+100.1', '100.10'], ['-45.55', '-45.55'], ['-0.00', '0.00'], ['92233720368547758.07', '92233720368547758.07'], ['-92233720368547758.08', '-92233720368547758.08']];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_or_inexact_input_is_rejected(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromDecimal($input);
    }

    public static function invalidAmounts(): array
    {
        return array_map(fn (string $value): array => [$value], ['', ' 1.00', '1.00 ', '1.001', '1e3', '1,000.00', '.10', '1.', 'NaN', '--1', '01.00', '92233720368547758.08', '-92233720368547758.09']);
    }

    #[DataProvider('overflowOperations')]
    public function test_arithmetic_overflow_is_rejected(int $left, int $right, string $operation): void
    {
        $this->expectException(OverflowException::class);
        Money::fromMinorUnits($left)->{$operation}(Money::fromMinorUnits($right));
    }

    public static function overflowOperations(): array
    {
        return [[PHP_INT_MAX, 1, 'add'], [PHP_INT_MIN, -1, 'add'], [PHP_INT_MIN, 1, 'subtract'], [PHP_INT_MAX, -1, 'subtract']];
    }
}
