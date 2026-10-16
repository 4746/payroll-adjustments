<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure;

use App\Payroll\Application\AdjustmentHistory;
use App\Payroll\Application\PayrollLineNotFound;
use App\Payroll\Application\PayrollQueries;
use App\Payroll\Domain\Money;
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
        $line = $this->stream->replay(id: $id);

        return new AdjustmentHistory(
            payrollLineId: $id,
            currentAmount: $line->amount(),
            frozenSystemAmount: $line->frozenSystemAmount(),
            adjustments: $line->adjustments(),
        );
    }
}
