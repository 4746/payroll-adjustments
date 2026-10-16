<?php

declare(strict_types=1);

namespace App\Payroll\Application;

use App\Payroll\Domain\Money;
use App\Payroll\Domain\PayrollLine;

/** Read current amounts, pages and complete audit history without changing state. */
interface PayrollQueries
{
    public function amount(string $id): Money;

    /** @return list<PayrollLine> */
    public function list(int $offset, int $limit): array;

    public function history(string $id): AdjustmentHistory;
}
