<?php

declare(strict_types=1);

namespace App\Payroll\Application;

use App\Payroll\Domain\ManualAdjustmentApplied;
use App\Payroll\Domain\Money;

final readonly class AdjustmentHistory
{
    /** @param list<ManualAdjustmentApplied> $adjustments */
    public function __construct(
        public string $payrollLineId,
        public Money $currentAmount,
        public ?Money $frozenSystemAmount,
        public array $adjustments,
    ) {}
}
