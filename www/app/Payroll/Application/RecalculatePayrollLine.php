<?php

declare(strict_types=1);

namespace App\Payroll\Application;

use App\Payroll\Domain\Money;
use App\Payroll\Domain\PayrollLine;

final readonly class RecalculatePayrollLine
{
    public function __construct(private EventStore $events) {}

    public function handle(string $id, Money|string $amount): PayrollLine
    {
        $line = $this->events->load(id: $id);
        $amount = is_string($amount) ? Money::fromDecimal(amount: $amount, currency: $line->amount()->currency) : $amount;
        $line->recalculate(amount: $amount);
        $this->events->append(line: $line);

        return $line;
    }
}
