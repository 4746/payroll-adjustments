<?php

declare(strict_types=1);

namespace App\Payroll\Application;

use App\Payroll\Domain\PayrollLine;

/**
 * Load aggregates for changes and commit state with append-only event batches.
 */
interface PayrollRepository
{
    public function load(string $id): PayrollLine;

    /**
     * Append a unit of work once. Reload before the next operation.
     * The entire pending batch must succeed or leave persisted history unchanged.
     * Implementations compare expectedVersion() to detect stale aggregate state.
     *
     * @throws ConcurrencyConflict
     */
    public function append(PayrollLine $line): void;
}
