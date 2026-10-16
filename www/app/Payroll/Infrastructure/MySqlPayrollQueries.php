<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure;

use App\Payroll\Application\AdjustmentHistory;
use App\Payroll\Application\PayrollLineNotFound;
use App\Payroll\Application\PayrollQueries;
use App\Payroll\Domain\ManualAdjustmentApplied;
use App\Payroll\Domain\Money;
use App\Payroll\Domain\PayrollEvent;
use App\Payroll\Domain\PayrollLine;
use Illuminate\Database\Connection;
use InvalidArgumentException;

/** Read current state in one query; replay the journal only for a complete audit. */
final readonly class MySqlPayrollQueries implements PayrollQueries
{
    public function __construct(
        private Connection $connection,
        private PayrollStateMapper $states,
        private MySqlPayrollEventStream $stream,
    ) {}

    public function amount(string $id): Money
    {
        $row = $this->connection->table('payrolls')->where('id', $id)->first();

        if ($row === null) {
            throw new PayrollLineNotFound(message: "Payroll line [{$id}] was not found.");
        }

        return $this->states->restore(row: $row)->amount();
    }

    /** @return list<PayrollLine> */
    public function list(int $offset, int $limit): array
    {
        if ($offset < 0 || $limit < 1 || $limit > 101) {
            throw new InvalidArgumentException(message: 'Invalid payroll list bounds.');
        }

        return $this->connection->table('payrolls')
            ->orderBy('id')->offset($offset)->limit($limit)->get()
            ->map($this->states->restore(...))->all();
    }

    public function history(string $id): AdjustmentHistory
    {
        $events = $this->stream->read(id: $id);
        $line = PayrollLine::reconstitute(id: $id, events: $events);

        return new AdjustmentHistory(
            payrollLineId: $id,
            currentAmount: $line->amount(),
            frozenSystemAmount: $line->frozenSystemAmount(),
            adjustments: array_values(array_filter($events, fn (PayrollEvent $event): bool => $event instanceof ManualAdjustmentApplied)),
        );
    }
}
