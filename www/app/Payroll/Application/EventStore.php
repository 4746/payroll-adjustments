<?php

declare(strict_types=1);

namespace App\Payroll\Application;

use App\Payroll\Domain\PayrollLine;

interface EventStore
{
    public function load(string $id): PayrollLine;

    /**
     * Append a unit of work once. Reload before the next operation.
     *
     * @throws ConcurrencyConflict
     */
    public function append(PayrollLine $line): void;
}
