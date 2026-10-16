<?php

declare(strict_types=1);

namespace App\Payroll\Application;

use App\Payroll\Domain\PayrollLine;

/**
 * Persistence boundary exposing reconstruction and append-only writes.
 * No operation can update or remove an existing audit fact.
 */
interface EventStore
{
    public function load(string $id): PayrollLine;

    public function history(string $id): AdjustmentHistory;

    /** @return list<PayrollLine> */
    public function list(int $offset, int $limit): array;

    public function rebuild(string $id): void;

    /**
     * Append a unit of work once. Reload before the next operation.
     * The entire pending batch must succeed or leave persisted history unchanged.
     * Implementations compare expectedVersion() to detect stale aggregate state.
     *
     * @throws ConcurrencyConflict
     */
    public function append(PayrollLine $line): void;
}
