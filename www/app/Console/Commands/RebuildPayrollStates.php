<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Payroll\Application\PayrollStateRebuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('payroll:rebuild-states {--id= : Rebuild only this payroll line}')]
#[Description('Rebuild current payroll states from the immutable event history')]
class RebuildPayrollStates extends Command
{
    public function handle(PayrollStateRebuilder $rebuilder): int
    {
        try {
            $id = $this->option('id');
            $ids = $id === null
                ? DB::table('payroll_events')
                    ->select('payroll_id')
                    ->distinct()
                    ->orderBy('payroll_id')
                    ->lazy(100)
                    ->pluck('payroll_id')
                : [$id];
            $count = 0;

            foreach ($ids as $payrollId) {
                $rebuilder->rebuild(id: $payrollId);
                $count++;
            }

            $this->info("Rebuilt payroll states: {$count}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
