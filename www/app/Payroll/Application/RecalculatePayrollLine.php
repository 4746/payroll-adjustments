<?php

declare(strict_types=1);

namespace App\Payroll\Application;

use App\Payroll\Domain\Money;

final readonly class RecalculatePayrollLine
{
    public function __construct(private EventStore $events) {}

    public function handle(string $id, Money $amount): void
    {
        $line = $this->events->load($id);
        $line->recalculate($amount);
        $this->events->append($line);
    }
}
