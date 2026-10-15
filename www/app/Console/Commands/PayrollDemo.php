<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Payroll\Application\ApplyManualAdjustment;
use App\Payroll\Application\ConcurrencyConflict;
use App\Payroll\Application\CreatePayrollLine;
use App\Payroll\Application\GetAdjustmentHistory;
use App\Payroll\Application\GetPayrollLineAmount;
use App\Payroll\Application\RecalculatePayrollLine;
use App\Payroll\Domain\ManualAdjustmentApplied;
use App\Payroll\Domain\Money;
use DateTimeZone;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('payroll:demo {--id= : New payroll line ID; defaults to a random ID}')]
#[Description('Persist the eight-step payroll scenario and display its audit history')]
class PayrollDemo extends Command
{
    public function handle(
        CreatePayrollLine $create,
        RecalculatePayrollLine $recalculate,
        ApplyManualAdjustment $adjust,
        GetPayrollLineAmount $amount,
        GetAdjustmentHistory $history,
    ): int {
        $id = $this->option('id') ?? 'demo-'.bin2hex(random_bytes(8));

        try {
            $create->handle($id, Money::fromDecimal('1000'));
            $this->line("Payroll line: {$id}");
            $this->displayAmount('1. Create', $id, $amount);
            $recalculate->handle($id, Money::fromDecimal('1050'));
            $this->displayAmount('2. Recalculate', $id, $amount);
            $adjust->handle($id, Money::fromDecimal('-45.55'), 'Unpaid leave');
            $this->displayAmount('3. Unpaid leave', $id, $amount);
            $recalculate->handle($id, Money::fromDecimal('1200'));
            $this->displayAmount('4. Recalculate (ignored)', $id, $amount);

            foreach ([
                ['100.10', 'Bonus'],
                ['-0.10', 'Correction A'],
                ['-0.20', 'Correction B'],
                ['0.20', 'Reverse correction B'],
            ] as $index => [$delta, $comment]) {
                $adjust->handle($id, Money::fromDecimal($delta), $comment);
                $this->displayAmount(($index + 5).". {$comment}", $id, $amount);
            }

            $audit = $history->handle($id);
            $this->line('Frozen system amount: $'.$audit->frozenSystemAmount->toDecimal());
            $this->table(['Version', 'Adjustment (USD)', 'Comment', 'Event ID', 'Timestamp (UTC)'], array_map(
                fn (ManualAdjustmentApplied $event): array => [
                    $event->version,
                    ($event->amount->minorUnits > 0 ? '+' : '').$event->amount->toDecimal(),
                    $event->comment,
                    $event->eventId,
                    $event->occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                ],
                $audit->adjustments,
            ));
            $this->line('Adjustments: '.count($audit->adjustments));
            $this->line('Final amount: $'.$audit->currentAmount->toDecimal());

            return self::SUCCESS;
        } catch (ConcurrencyConflict|InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function displayAmount(string $step, string $id, GetPayrollLineAmount $amount): void
    {
        $this->line($step.': $'.$amount->handle($id)->toDecimal());
    }
}
