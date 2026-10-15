<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll\Infrastructure;

use App\Payroll\Application\ApplyManualAdjustment;
use App\Payroll\Application\ConcurrencyConflict;
use App\Payroll\Application\CreatePayrollLine;
use App\Payroll\Application\EventStore;
use App\Payroll\Application\GetAdjustmentHistory;
use App\Payroll\Application\GetPayrollLineAmount;
use App\Payroll\Application\PayrollLineNotFound;
use App\Payroll\Application\RecalculatePayrollLine;
use App\Payroll\Domain\InvalidAdjustment;
use App\Payroll\Domain\Money;
use App\Payroll\Domain\PayrollLine;
use App\Payroll\Infrastructure\EventSerializer;
use App\Payroll\Infrastructure\MySqlEventStore;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class MySqlEventStoreTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_event_batches_persist_in_application_order_and_reload_with_metadata(): void
    {
        $store = $this->app->make(EventStore::class);
        $line = PayrollLine::create('line-1', Money::fromDecimal('1000'));
        $line->recalculate(Money::fromDecimal('1050'));
        $line->applyAdjustment(Money::fromDecimal('-45.55'), 'Unpaid leave');

        $store->append($line);
        $reloaded = $store->load('line-1');

        $this->assertInstanceOf(MySqlEventStore::class, $store);
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('1004.45', $reloaded->amount()->toDecimal());
        $this->assertSame('1050.00', $reloaded->frozenSystemAmount()->toDecimal());
        $this->assertSame([], $reloaded->pendingEvents());
        $this->assertSame([1, 2, 3], DB::table('payroll_events')->orderBy('version')->pluck('version')->all());
        $adjustment = $reloaded->adjustments()[0];
        $this->assertSame('Unpaid leave', $adjustment->comment);
        $this->assertSame($line->adjustments()[0]->eventId, $adjustment->eventId);
        $this->assertSame($line->adjustments()[0]->occurredAt->format('U.u'), $adjustment->occurredAt->format('U.u'));
        $this->assertDatabaseCount('payroll_events', 3);
    }

    public function test_stale_writers_receive_a_conflict_without_losing_the_winning_adjustment(): void
    {
        $store = $this->app->make(EventStore::class);
        $store->append(PayrollLine::create('line-1', Money::fromDecimal('1000')));
        $winner = $store->load('line-1');
        $stale = $store->load('line-1');
        $winner->applyAdjustment(Money::fromDecimal('10'), 'Winner');
        $stale->applyAdjustment(Money::fromDecimal('-20'), 'Stale');
        $store->append($winner);

        try {
            $store->append($stale);
            $this->fail('Expected concurrency conflict.');
        } catch (ConcurrencyConflict $exception) {
            $this->assertStringContainsString('line-1', $exception->getMessage());
            $this->assertSame('1010.00', $store->load('line-1')->amount()->toDecimal());
            $this->assertSame('Winner', $store->load('line-1')->adjustments()[0]->comment);
            $this->assertDatabaseCount('payroll_events', 2);
        }
    }

    public function test_duplicate_creation_is_a_clear_conflict(): void
    {
        $store = $this->app->make(EventStore::class);
        $store->append(PayrollLine::create('line-1', Money::fromDecimal('1000')));
        $this->expectException(ConcurrencyConflict::class);
        $store->append(PayrollLine::create('line-1', Money::fromDecimal('2000')));
    }

    public function test_a_committed_competing_connection_wins_the_insert_race(): void
    {
        DB::table('payroll_events')->count();
        $configuration = config('database.connections.payroll_testing');
        config(['database.connections.payroll_writer' => $configuration, 'database.connections.payroll_racer' => $configuration]);
        $writerConnection = DB::connection('payroll_writer');
        $racerConnection = DB::connection('payroll_racer');
        $writer = new MySqlEventStore($writerConnection, new EventSerializer);
        $racer = new MySqlEventStore($racerConnection, new EventSerializer);
        $raced = false;
        $writerConnection->beforeExecuting(function (string $query) use (&$raced, $racer): void {
            if (! $raced && str_starts_with($query, 'insert into `payroll_events`')) {
                $raced = true;
                $racer->append(PayrollLine::create('race-line', Money::fromDecimal('2000')));
            }
        });

        try {
            try {
                $writer->append(PayrollLine::create('race-line', Money::fromDecimal('1000')));
                $this->fail('Expected a concurrency conflict after the competing commit.');
            } catch (ConcurrencyConflict) {
                $this->assertTrue($raced);
                $this->assertSame('2000.00', $writer->load('race-line')->amount()->toDecimal());
                $this->assertSame(1, $writerConnection->table('payroll_events')->where('aggregate_id', 'race-line')->count());
            }
        } finally {
            $racerConnection->table('payroll_events')->where('aggregate_id', 'race-line')->delete();
            DB::purge('payroll_writer');
            DB::purge('payroll_racer');
        }
    }

    public function test_unique_constraint_closes_the_race_after_the_version_check(): void
    {
        $store = $this->app->make(EventStore::class);
        $first = PayrollLine::create('line-1', Money::fromDecimal('1000'));
        $racer = PayrollLine::create('line-1', Money::fromDecimal('2000'));
        $connection = DB::connection();
        $raced = false;
        $connection->beforeExecuting(function (string $query) use (&$raced, $connection, $racer): void {
            if (! $raced && str_starts_with($query, 'insert into `payroll_events`')) {
                $raced = true;
                $connection->table('payroll_events')->insert((new EventSerializer)->serialize($racer->pendingEvents()[0]));
            }
        });
        try {
            $store->append($first);
            $this->fail('Expected the unique stream-version constraint to reject the raced write.');
        } catch (ConcurrencyConflict) {
            $this->assertTrue($raced);
            $this->assertDatabaseCount('payroll_events', 0);
        }
    }

    public function test_failed_second_insert_rolls_back_the_entire_batch_and_can_be_retried(): void
    {
        $store = $this->app->make(EventStore::class);
        $store->append(PayrollLine::create('line-1', Money::fromDecimal('1000')));
        $line = $store->load('line-1');
        $line->applyAdjustment(Money::fromDecimal('-45.55'), 'First adjustment');
        $line->applyAdjustment(Money::fromDecimal('100.10'), 'Second adjustment');
        $attempts = 0;
        DB::connection()->beforeExecuting(function (string $query) use (&$attempts): void {
            if (str_starts_with($query, 'insert into `payroll_events`') && ++$attempts === 2) {
                throw new RuntimeException('Simulated write failure');
            }
        });

        try {
            $store->append($line);
            $this->fail('Expected write failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated write failure', $exception->getMessage());
            $this->assertDatabaseCount('payroll_events', 1);
            $this->assertSame('1000.00', $store->load('line-1')->amount()->toDecimal());
            $this->assertSame([], $store->load('line-1')->adjustments());
        }
        $store->append($line);
        $this->assertDatabaseCount('payroll_events', 3);
        $this->assertSame('1054.55', $store->load('line-1')->amount()->toDecimal());
    }

    public function test_actual_database_failure_does_not_leave_partial_history(): void
    {
        $store = $this->app->make(EventStore::class);
        $line = PayrollLine::create('line-1', Money::fromDecimal('1000'));
        $line->applyAdjustment(Money::fromDecimal('10'), 'Will fail');
        $attempts = 0;
        DB::connection()->beforeExecuting(function (string $query) use (&$attempts): void {
            if (str_starts_with($query, 'insert into `payroll_events`') && ++$attempts === 2) {
                DB::statement("SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected failure'");
            }
        });
        try {
            $store->append($line);
            $this->fail('Expected database failure.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Injected failure', $exception->getMessage());
            $this->assertDatabaseCount('payroll_events', 0);
        }
        $store->append($line);
        $this->assertSame('1010.00', $store->load('line-1')->amount()->toDecimal());
    }

    public function test_application_use_cases_preserve_the_full_scenario_and_isolate_streams(): void
    {
        $create = $this->app->make(CreatePayrollLine::class);
        $recalculate = $this->app->make(RecalculatePayrollLine::class);
        $adjust = $this->app->make(ApplyManualAdjustment::class);
        $amount = $this->app->make(GetPayrollLineAmount::class);
        $history = $this->app->make(GetAdjustmentHistory::class);
        $create->handle('line-1', Money::fromDecimal('1000'));
        $create->handle('line-2', Money::fromDecimal('500'));
        $this->assertSame('1000.00', $amount->handle('line-1')->toDecimal());
        $this->assertNull($history->handle('line-1')->frozenSystemAmount);
        $recalculate->handle('line-1', Money::fromDecimal('1050'));
        $this->assertSame('1050.00', $amount->handle('line-1')->toDecimal());
        $adjust->handle('line-1', Money::fromDecimal('-45.55'), 'Unpaid leave');
        $this->assertSame('1004.45', $amount->handle('line-1')->toDecimal());
        $before = DB::table('payroll_events')->where('aggregate_id', 'line-1')->orderBy('version')->get()->toJson();
        $recalculate->handle('line-1', Money::fromDecimal('1200'));
        $this->assertSame('1004.45', $amount->handle('line-1')->toDecimal());
        $this->assertSame($before, DB::table('payroll_events')->where('aggregate_id', 'line-1')->orderBy('version')->get()->toJson());
        foreach ([['100.10', 'Bonus', '1104.55'], ['-0.10', 'Correction A', '1104.45'], ['-0.20', 'Correction B', '1104.25'], ['0.20', 'Reverse B', '1104.45']] as [$delta, $comment, $expected]) {
            $adjust->handle('line-1', Money::fromDecimal($delta), $comment);
            $this->assertSame($expected, $amount->handle('line-1')->toDecimal());
        }
        $audit = $history->handle('line-1');
        $this->assertSame('1050.00', $audit->frozenSystemAmount->toDecimal());
        $this->assertSame('1104.45', $audit->currentAmount->toDecimal());
        $this->assertCount(5, $audit->adjustments);
        $this->assertSame(['Unpaid leave', 'Bonus', 'Correction A', 'Correction B', 'Reverse B'], array_column($audit->adjustments, 'comment'));
        $this->assertSame('500.00', $amount->handle('line-2')->toDecimal());
        $this->assertSame([], $history->handle('line-2')->adjustments);
        $this->assertDatabaseCount('payroll_events', 8);
    }

    public function test_domain_rejection_never_appends_an_event(): void
    {
        $this->app->make(CreatePayrollLine::class)->handle('line-1', Money::fromDecimal('1000'));
        try {
            $this->app->make(ApplyManualAdjustment::class)->handle('line-1', Money::fromDecimal('1'), ' ');
            $this->fail('Expected invalid adjustment.');
        } catch (InvalidAdjustment) {
            $this->assertDatabaseCount('payroll_events', 1);
            $this->assertSame('1000.00', $this->app->make(GetPayrollLineAmount::class)->handle('line-1')->toDecimal());
        }
    }

    public function test_missing_payroll_line_has_an_application_level_failure(): void
    {
        $this->expectException(PayrollLineNotFound::class);
        $this->app->make(GetPayrollLineAmount::class)->handle('missing');
    }
}
