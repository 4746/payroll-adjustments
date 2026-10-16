<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Payroll\Application\ConcurrencyConflict;
use App\Payroll\Application\EventStore;
use App\Payroll\Domain\Money;
use App\Payroll\Domain\PayrollLine;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PayrollLineHttpTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string BASE = '/api/v1/payroll-lines';

    public function test_currency_is_inherited_by_recalculations_adjustments_and_persisted_events(): void
    {
        $this->postJson(self::BASE, ['id' => 'euro-line', 'amount' => '1000.00', 'currency' => 'EUR'])
            ->assertCreated()->assertJsonPath('data.currency', 'EUR');
        $this->postJson(self::BASE.'/euro-line/recalculations', ['amount' => '1050.00'])
            ->assertOk()->assertJsonPath('data.currency', 'EUR');
        $this->postJson(self::BASE.'/euro-line/adjustments', ['amount' => '-45.55', 'comment' => 'Leave'])
            ->assertCreated()->assertJsonPath('data.currency', 'EUR')->assertJsonPath('data.current_amount', '1004.45');
        $this->postJson(self::BASE.'/euro-line/recalculations', ['amount' => '1200.00'])
            ->assertOk()->assertJsonPath('data.current_amount', '1004.45');
        $this->getJson(self::BASE.'/euro-line')->assertOk()
            ->assertJsonPath('data.currency', 'EUR')->assertJsonPath('data.current_amount', '1004.45');
        $this->getJson(self::BASE.'/euro-line/adjustments')->assertOk()
            ->assertJsonPath('data.currency', 'EUR')->assertJsonPath('data.frozen_system_amount', '1050.00');

        $payloads = DB::table('payroll_events')->where('aggregate_id', 'euro-line')->orderBy('version')->pluck('payload');
        $this->assertCount(3, $payloads);
        foreach ($payloads as $payload) {
            $this->assertSame('EUR', json_decode($payload, true, 512, JSON_THROW_ON_ERROR)['currency']);
        }
    }

    public function test_invalid_creation_currency_is_rejected_without_writing(): void
    {
        $this->postJson(self::BASE, ['id' => 'invalid-currency', 'amount' => '1.00', 'currency' => 'XYZ'])
            ->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->assertDatabaseCount('payroll_events', 0);
    }

    public function test_subsequent_requests_cannot_override_the_initial_currency(): void
    {
        $this->postJson(self::BASE, ['id' => 'uah-line', 'amount' => '100.00', 'currency' => 'UAH'])
            ->assertCreated()->assertJsonPath('data.currency', 'UAH');
        $this->postJson(self::BASE.'/uah-line/adjustments', ['amount' => '1.00', 'currency' => 'USD', 'comment' => 'Bonus'])
            ->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->postJson(self::BASE.'/uah-line/recalculations', ['amount' => '200.00', 'currency' => 'USD'])
            ->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->assertDatabaseCount('payroll_events', 1);
    }

    public function test_http_scenario_persists_five_adjustments_and_reads_the_replayed_audit(): void
    {
        $this->postJson(self::BASE, ['id' => 'http-line', 'amount' => '1000.00'])
            ->assertCreated()->assertJsonPath('data.current_amount', '1000.00')
            ->assertJsonPath('data.frozen_system_amount', null);
        $this->postJson(self::BASE.'/http-line/recalculations', ['amount' => '1050.00'])
            ->assertOk()->assertJsonPath('data.current_amount', '1050.00');
        $this->postJson(self::BASE.'/http-line/adjustments', ['amount' => '-45.55', 'comment' => 'Unpaid leave'])
            ->assertCreated()->assertJsonPath('data.current_amount', '1004.45');
        $this->postJson(self::BASE.'/http-line/recalculations', ['amount' => '1200.00'])
            ->assertOk()->assertJsonPath('data.current_amount', '1004.45')
            ->assertJsonPath('data.frozen_system_amount', '1050.00');
        $this->assertDatabaseCount('payroll_events', 3);

        foreach ([['100.10', 'Bonus', '1104.55'], ['-0.10', 'Correction A', '1104.45'], ['-0.20', 'Correction B', '1104.25'], ['0.20', 'Reverse correction B', '1104.45']] as [$amount, $comment, $expected]) {
            $this->postJson(self::BASE.'/http-line/adjustments', compact('amount', 'comment'))
                ->assertCreated()->assertJsonPath('data.current_amount', $expected);
        }

        $this->getJson(self::BASE.'/http-line')->assertOk()->assertExactJson([
            'data' => ['id' => 'http-line', 'currency' => 'USD', 'current_amount' => '1104.45'],
        ]);
        $audit = $this->getJson(self::BASE.'/http-line/adjustments')->assertOk()
            ->assertJsonPath('data.frozen_system_amount', '1050.00')
            ->assertJsonPath('data.current_amount', '1104.45')
            ->assertJsonCount(5, 'data.adjustments')
            ->assertJsonPath('data.adjustments.0.amount', '-45.55')
            ->assertJsonPath('data.adjustments.4.amount', '0.20');
        $this->assertSame(['Unpaid leave', 'Bonus', 'Correction A', 'Correction B', 'Reverse correction B'], array_column($audit->json('data.adjustments'), 'comment'));
        $this->assertSame([3, 4, 5, 6, 7], array_column($audit->json('data.adjustments'), 'version'));
        $first = DB::table('payroll_events')->where('aggregate_id', 'http-line')->where('version', 3)->first();
        $this->assertSame($first->event_id, $audit->json('data.adjustments.0.event_id'));
        $this->assertMatchesRegularExpression('/^\\d{4}-\\d{2}-\\d{2}T.*\\.\\d{6}Z$/', $audit->json('data.adjustments.0.occurred_at'));
        $this->assertDatabaseCount('payroll_events', 7);
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amounts_return_422_without_writing(mixed $amount): void
    {
        $this->postJson(self::BASE, ['id' => 'invalid', 'amount' => $amount])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('payroll_events', 0);
    }

    public static function invalidAmounts(): array
    {
        return [[1000.10], [1000], ['1.001'], [' 1.00 '], ['1e3'], ['92233720368547758.08'], [null], [[]]];
    }

    public function test_required_fields_and_invalid_ids_return_validation_errors(): void
    {
        $this->postJson(self::BASE, [])->assertUnprocessable()->assertJsonValidationErrors(['id', 'amount']);
        $this->postJson(self::BASE, ['id' => 'invalid id', 'amount' => '1000'])
            ->assertUnprocessable()->assertJsonValidationErrors('id');
        $this->assertDatabaseCount('payroll_events', 0);
    }

    public function test_missing_lines_return_404_for_each_existing_line_operation(): void
    {
        $this->getJson(self::BASE.'/missing')->assertNotFound();
        $this->getJson(self::BASE.'/missing/adjustments')->assertNotFound();
        $this->postJson(self::BASE.'/missing/recalculations', ['amount' => '1000'])->assertNotFound();
        $this->postJson(self::BASE.'/missing/adjustments', ['amount' => '10', 'comment' => 'Missing'])->assertNotFound();
        $this->assertDatabaseCount('payroll_events', 0);
    }

    public function test_duplicate_creation_returns_409_without_replacing_history(): void
    {
        $this->postJson(self::BASE, ['id' => 'line', 'amount' => '1000'])->assertCreated();
        $this->postJson(self::BASE, ['id' => 'line', 'amount' => '2000'])->assertConflict();
        $this->getJson(self::BASE.'/line')->assertJsonPath('data.current_amount', '1000.00');
        $this->assertDatabaseCount('payroll_events', 1);
    }

    public function test_write_conflicts_are_rendered_as_409(): void
    {
        $this->postJson(self::BASE, ['id' => 'line', 'amount' => '1000'])->assertCreated();
        $store = $this->mock(EventStore::class);
        $store->shouldReceive('load')->with('line')->once()->andReturn(PayrollLine::create('line', Money::fromDecimal('1000')));
        $store->shouldReceive('append')->once()->andThrow(new ConcurrencyConflict('Reload before retrying.'));
        $this->postJson(self::BASE.'/line/adjustments', ['amount' => '1', 'comment' => 'Conflict'])
            ->assertConflict()->assertJsonPath('message', 'Reload before retrying.');
        $this->assertDatabaseCount('payroll_events', 1);
    }

    public function test_zero_and_blank_comment_adjustments_are_rejected_without_new_events(): void
    {
        $this->postJson(self::BASE, ['id' => 'line', 'amount' => '1000'])->assertCreated();
        $this->postJson(self::BASE.'/line/adjustments', ['amount' => '0', 'comment' => 'No change'])->assertUnprocessable();
        $this->postJson(self::BASE.'/line/adjustments', ['amount' => '1', 'comment' => ' '])->assertUnprocessable();
        $this->postJson(self::BASE.'/line/adjustments', ['amount' => '1'])->assertUnprocessable()->assertJsonValidationErrors('comment');
        $this->assertDatabaseCount('payroll_events', 1);
    }

    public function test_comment_whitespace_and_unicode_are_preserved_exactly(): void
    {
        $comment = "  Коригування зарплати\n  ";
        $this->postJson(self::BASE, ['id' => 'line', 'amount' => '1000'])->assertCreated();
        $this->postJson(self::BASE.'/line/adjustments', ['amount' => '1', 'comment' => $comment])
            ->assertCreated()->assertJsonPath('data.adjustments.0.comment', $comment);
        $this->getJson(self::BASE.'/line/adjustments')->assertJsonPath('data.adjustments.0.comment', $comment);
        $this->assertDatabaseCount('payroll_events', 2);
    }

    public function test_overflow_returns_422_without_freezing_or_appending(): void
    {
        $this->postJson(self::BASE, ['id' => 'line', 'amount' => '92233720368547758.07'])->assertCreated();
        $this->postJson(self::BASE.'/line/adjustments', ['amount' => '0.01', 'comment' => 'Overflow'])->assertUnprocessable();
        $this->getJson(self::BASE.'/line/adjustments')->assertJsonPath('data.frozen_system_amount', null)->assertJsonCount(0, 'data.adjustments');
        $this->assertDatabaseCount('payroll_events', 1);
    }

    public function test_reading_and_adjusting_one_line_does_not_change_another(): void
    {
        $this->postJson(self::BASE, ['id' => 'line-a', 'amount' => '1000'])->assertCreated();
        $this->postJson(self::BASE, ['id' => 'line-b', 'amount' => '500'])->assertCreated();
        $this->postJson(self::BASE.'/line-a/adjustments', ['amount' => '1', 'comment' => 'Only A'])->assertCreated();
        $this->getJson(self::BASE.'/line-b')->assertJsonPath('data.current_amount', '500.00');
        $this->getJson(self::BASE.'/line-b/adjustments')->assertJsonCount(0, 'data.adjustments');
        $this->assertDatabaseCount('payroll_events', 3);
    }
}
