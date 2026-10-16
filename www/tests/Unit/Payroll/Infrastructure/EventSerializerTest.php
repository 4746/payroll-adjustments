<?php

declare(strict_types=1);

namespace Tests\Unit\Payroll\Infrastructure;

use App\Payroll\Domain\Currency;
use App\Payroll\Domain\InvalidHistory;
use App\Payroll\Domain\ManualAdjustmentApplied;
use App\Payroll\Domain\Money;
use App\Payroll\Domain\PayrollLineCalculated;
use App\Payroll\Infrastructure\EventSerializer;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EventSerializerTest extends TestCase
{
    public function test_serialization_round_trips_exact_amounts_comments_and_utc_microseconds(): void
    {
        $serializer = new EventSerializer;
        foreach ([
            new PayrollLineCalculated('line-1', str_repeat('a', 32), 1, new DateTimeImmutable('2026-01-01T12:00:00.123456+02:00'), Money::fromMinorUnits(PHP_INT_MIN)),
            new ManualAdjustmentApplied('line-1', str_repeat('b', 32), 2, new DateTimeImmutable('2026-01-01T10:00:00.123456Z'), Money::fromDecimal('-45.55'), 'Leave "correction"'),
            new PayrollLineCalculated('euro-line', str_repeat('c', 32), 1, new DateTimeImmutable('2026-01-01T10:00:00.123456Z'), Money::fromDecimal('100', Currency::Eur)),
        ] as $event) {
            $row = $serializer->serialize($event);
            $this->assertSame($event->amount->currency, $serializer->deserialize((object) $row)->amount->currency);
            $this->assertSame('2026-01-01 10:00:00.123456', $row['occurred_at']);
            $this->assertSame($row, $serializer->serialize($serializer->deserialize((object) $row)));
        }
    }

    #[DataProvider('corruptRows')]
    public function test_invalid_persisted_data_fails_closed(string $field, mixed $value): void
    {
        $serializer = new EventSerializer;
        $row = $serializer->serialize(new PayrollLineCalculated('line-1', str_repeat('a', 32), 1, new DateTimeImmutable('2026-01-01Z'), Money::fromDecimal('1')));
        $row[$field] = $value;
        $this->expectException(InvalidHistory::class);
        $serializer->deserialize((object) $row);
    }

    public static function corruptRows(): array
    {
        return [
            ['event_type', 'unknown.v1'],
            ['payload', '{'],
            ['payload', '{"schema_version":2,"currency":"USD","amount_minor":"100"}'],
            ['payload', '{"schema_version":1,"currency":"XYZ","amount_minor":"100"}'],
            ['payload', '{"schema_version":1,"currency":null,"amount_minor":"100"}'],
            ['payload', '{"schema_version":1,"currency":1,"amount_minor":"100"}'],
            ['payload', '{"schema_version":1,"currency":true,"amount_minor":"100"}'],
            ['payload', '{"schema_version":1,"currency":["USD"],"amount_minor":"100"}'],
            ['payload', '{"schema_version":1,"currency":{"value":"USD"},"amount_minor":"100"}'],
            ['payload', '{"schema_version":1,"currency":"USD","amount_minor":1.1}'],
            ['payload', '{"schema_version":1,"currency":"USD","amount_minor":"1.1"}'],
            ['payload', '{"schema_version":1,"currency":"USD","amount_minor":"9223372036854775808"}'],
            ['payload', '{"schema_version":1,"currency":"USD","amount_minor":"100","comment":"unexpected"}'],
            ['occurred_at', '2026-02-30 10:00:00.000000'],
        ];
    }
}
