<?php

declare(strict_types=1);

namespace App\Payroll\Domain;

enum PayrollEventType: string
{
    case Calculated = 'calculated.v1';
    case ManualAdjustmentApplied = 'manual_adjustment_applied.v1';
}
