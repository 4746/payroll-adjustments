<?php

declare(strict_types=1);

namespace App\Payroll\Application;

use App\Payroll\Domain\Money;
use App\Payroll\Domain\PayrollLine;

final readonly class CreatePayrollLine
{
    public function __construct(private EventStore $events) {}

    public function handle(string $id, Money $amount): void
    {
        $this->events->append(PayrollLine::create($id, $amount));
    }
}
