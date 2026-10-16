<?php

declare(strict_types=1);

namespace App\Payroll\Application;

use App\Payroll\Domain\Money;

final readonly class GetPayrollLineAmount
{
    public function __construct(private EventStore $events) {}

    public function handle(string $id): Money
    {
        return $this->events->load(id: $id)->amount();
    }
}
