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
        $line = $this->events->load($id);
        $amount = is_string($amount) ? Money::fromDecimal($amount, $line->amount()->currency) : $amount;
        $line->applyAdjustment($amount, $comment);
        $this->events->append($line);

        return $line;
    }
}
