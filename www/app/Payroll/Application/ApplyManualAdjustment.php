<?php

declare(strict_types=1);

namespace App\Payroll\Application;

use App\Payroll\Domain\Money;
use App\Payroll\Domain\PayrollLine;

final readonly class ApplyManualAdjustment
{
    public function __construct(private EventStore $events) {}

    public function handle(string $id, Money|string $amount, string $comment): PayrollLine
    {
        $line = $this->events->load(id: $id);
        $amount = is_string($amount) ? Money::fromDecimal(amount: $amount, currency: $line->amount()->currency) : $amount;
        $line->applyAdjustment(amount: $amount, comment: $comment);
        $this->events->append(line: $line);

        return $line;
    }
}
