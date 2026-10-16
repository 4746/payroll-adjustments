<?php

declare(strict_types=1);

namespace App\Payroll\Application;

use App\Payroll\Domain\PayrollLine;
use InvalidArgumentException;

final readonly class GetPayrollLines
{
    public function __construct(private PayrollQueries $queries) {}

    /** @return list<PayrollLine> */
    public function handle(int $page = 1, int $perPage = 100): array
    {
        if ($page < 1 || $page > 1000000 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException(message: 'Invalid payroll list page.');
        }

        return $this->queries->list(offset: ($page - 1) * $perPage, limit: $perPage + 1);
    }
}
