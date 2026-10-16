<?php

declare(strict_types=1);

namespace App\Payroll\Application;

final readonly class GetAdjustmentHistory
{
    public function __construct(private PayrollQueries $queries) {}

    public function handle(string $id): AdjustmentHistory
    {
        return $this->queries->history(id: $id);
    }
}
