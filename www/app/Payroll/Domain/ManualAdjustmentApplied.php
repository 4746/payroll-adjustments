<?php

declare(strict_types=1);

namespace App\Payroll\Domain;

/**
 * A signed delta with its original explanation, preserved as a separate fact.
 * The first such fact freezes the baseline; compensation adds another fact.
 */
final readonly class ManualAdjustmentApplied extends PayrollEvent {}
