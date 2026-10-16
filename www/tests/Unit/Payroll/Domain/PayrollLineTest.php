<?php

declare(strict_types=1);

namespace Tests\Unit\Payroll\Domain;

use App\Payroll\Domain\Currency;
use App\Payroll\Domain\InvalidAdjustment;
use App\Payroll\Domain\InvalidHistory;
use App\Payroll\Domain\ManualAdjustmentApplied;
use App\Payroll\Domain\Money;
use App\Payroll\Domain\PayrollLine;
use App\Payroll\Domain\PayrollLineCalculated;
use DateTimeImmutable;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PayrollLineTest extends TestCase
{
    #[DataProvider('currencyChanges')]
    public function test_live_operations_reject_currency_changes(string $operation): void
    {
        $line = PayrollLine::create('line-1', Money::fromDecimal('100', Currency::Eur));
        $this->expectException(InvalidAdjustment::class);

        if ($operation === 'adjustment') {
            $line->applyAdjustment(Money::fromDecimal('1', Currency::Usd), 'Wrong currency');
        } else {
            $line->recalculate(Money::fromDecimal('200', Currency::Usd));
        }
    }

    #[DataProvider('currencyChanges')]
    public function test_replay_rejects_currency_changes(string $operation): void
    {
        $line = PayrollLine::create('line-1', Money::fromDecimal('100', Currency::Eur));
        $eventClass = $operation === 'adjustment' ? ManualAdjustmentApplied::class : PayrollLineCalculated::class;
        $event = new $eventClass(
            'line-1', str_repeat('a', 32), 2, new DateTimeImmutable('2026-01-01Z'),
            Money::fromDecimal('1', Currency::Usd), $operation === 'adjustment' ? 'Wrong currency' : null,
        );
        $this->expectException(InvalidHistory::class);
        PayrollLine::reconstitute('line-1', [...$line->pendingEvents(), $event]);
    }

    /** @return list<array{string}> */
    public static function currencyChanges(): array
    {
        return [['adjustment'], ['calculation']];
    }

    public function test_calculation_can_change_until_the_first_adjustment(): void
    {
        $line = PayrollLine::create('line-1', Money::fromDecimal('1000'));
        $this->assertSame('1000.00', $line->amount()->toDecimal());
        $this->assertNull($line->frozenSystemAmount());
        $line->recalculate(Money::fromDecimal('1050'));
        $this->assertSame('1050.00', $line->amount()->toDecimal());
        $this->assertCount(2, $line->pendingEvents());
    }

    public function test_complete_scenario_preserves_every_amount_comment_and_order_through_replay(): void
    {
        $line = PayrollLine::create('line-1', Money::fromDecimal('1000'));
        $line->recalculate(Money::fromDecimal('1050'));
        $line->applyAdjustment(Money::fromDecimal('-45.55'), 'Unpaid leave');
        $this->assertSame('1004.45', $line->amount()->toDecimal());
        $eventsBefore = $line->pendingEvents();
        $line->recalculate(Money::fromDecimal('1200'));
        $this->assertSame($eventsBefore, $line->pendingEvents());
        $this->assertSame('1004.45', $line->amount()->toDecimal());
        foreach ([['100.10', 'Bonus', '1104.55'], ['-0.10', 'Correction A', '1104.45'], ['-0.20', 'Correction B', '1104.25'], ['0.20', 'Reverse correction B', '1104.45']] as [$amount, $comment, $expected]) {
            $line->applyAdjustment(Money::fromDecimal($amount), $comment);
            $this->assertSame($expected, $line->amount()->toDecimal());
        }
        $events = $line->pendingEvents();
        $reloaded = PayrollLine::reconstitute('line-1', $events);
        $this->assertSame('1104.45', $reloaded->amount()->toDecimal());
        $this->assertSame('1050.00', $reloaded->frozenSystemAmount()->toDecimal());
        $this->assertSame(['-45.55', '100.10', '-0.10', '-0.20', '0.20'], array_map(fn (ManualAdjustmentApplied $event): string => $event->amount->toDecimal(), $reloaded->adjustments()));
        $this->assertSame(['Unpaid leave', 'Bonus', 'Correction A', 'Correction B', 'Reverse correction B'], array_map(fn (ManualAdjustmentApplied $event): string => $event->comment, $reloaded->adjustments()));
        $this->assertSame([3, 4, 5, 6, 7], array_column($reloaded->adjustments(), 'version'));
        $this->assertCount(5, $reloaded->adjustments());
        $this->assertCount(7, array_unique(array_column($events, 'eventId')));
        $this->assertSame([], $reloaded->pendingEvents());
        $this->assertSame($events[2]->occurredAt, $reloaded->adjustments()[0]->occurredAt);
    }

    public function test_compensation_never_reenables_automatic_calculations(): void
    {
        $line = PayrollLine::create('line-1', Money::fromDecimal('1000'));
        $line->applyAdjustment(Money::fromDecimal('-10'), 'Deduct');
        $line->applyAdjustment(Money::fromDecimal('10'), 'Compensate');
        $line = PayrollLine::reconstitute('line-1', $line->pendingEvents());
        $line->recalculate(Money::fromDecimal('9999'));
        $this->assertSame('1000.00', $line->amount()->toDecimal());
        $this->assertSame('1000.00', $line->frozenSystemAmount()->toDecimal());
        $this->assertSame([], $line->pendingEvents());
        $this->assertCount(2, $line->adjustments());
    }

    #[DataProvider('rejectedAdjustments')]
    public function test_rejected_adjustment_does_not_change_state_or_record_an_event(string $amount, string $comment): void
    {
        $line = PayrollLine::create('line-1', Money::fromDecimal('1000'));
        try {
            $line->applyAdjustment(Money::fromDecimal($amount), $comment);
            $this->fail('Expected rejection.');
        } catch (InvalidAdjustment) {
            $this->assertSame('1000.00', $line->amount()->toDecimal());
            $this->assertNull($line->frozenSystemAmount());
            $this->assertCount(1, $line->pendingEvents());
            $this->assertSame([], $line->adjustments());
        }
    }

    public static function rejectedAdjustments(): array
    {
        return [['1', ''], ['1', " \t\n"], ['1', "\u{00A0}"], ['0', 'No change']];
    }

    public function test_overflow_does_not_freeze_the_baseline_or_record_an_event(): void
    {
        $line = PayrollLine::create('line-1', Money::fromMinorUnits(PHP_INT_MAX));
        try {
            $line->applyAdjustment(Money::fromMinorUnits(1), 'Too large');
            $this->fail('Expected overflow.');
        } catch (OverflowException) {
            $this->assertNull($line->frozenSystemAmount());
            $this->assertCount(1, $line->pendingEvents());
            $this->assertSame(PHP_INT_MAX, $line->amount()->minorUnits);
        }
    }

    #[DataProvider('corruptHistories')]
    public function test_invalid_history_is_rejected(array $events): void
    {
        $this->expectException(InvalidHistory::class);
        PayrollLine::reconstitute('line-1', $events);
    }

    public static function corruptHistories(): array
    {
        $time = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $first = new PayrollLineCalculated('line-1', str_repeat('a', 32), 1, $time, Money::fromDecimal('1'));
        $adjustment = new ManualAdjustmentApplied('line-1', str_repeat('b', 32), 2, $time, Money::fromDecimal('1'), 'Comment');

        return [
            [[]],
            [[$adjustment]],
            [[$first, $first]],
            [[$first, new PayrollLineCalculated('other', str_repeat('b', 32), 2, $time, Money::fromDecimal('1'))]],
            [[$first, new PayrollLineCalculated('line-1', str_repeat('a', 32), 2, $time, Money::fromDecimal('1'))]],
            [[$first, $adjustment, new PayrollLineCalculated('line-1', str_repeat('c', 32), 3, $time, Money::fromDecimal('1'))]],
        ];
    }
}
