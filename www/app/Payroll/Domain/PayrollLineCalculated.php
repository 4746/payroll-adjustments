<?php

declare(strict_types=1);

namespace App\Payroll\Domain;

/**
 * An accepted automatic total that replaces the previous calculation.
 * It is valid only before manual override; amount is a total, not a delta.
 */
final readonly class PayrollLineCalculated extends PayrollEvent {}
