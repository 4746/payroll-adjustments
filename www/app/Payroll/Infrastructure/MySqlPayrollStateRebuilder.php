<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure;

use App\Payroll\Application\PayrollStateRebuilder;
use Illuminate\Database\Connection;

/** Rebuild under state and journal locks so concurrent writes cannot be overwritten. */
final readonly class MySqlPayrollStateRebuilder implements PayrollStateRebuilder
{
    public function __construct(
        private Connection $connection,
        private PayrollStateMapper $states,
        private MySqlPayrollEventStream $stream,
    ) {}

    public function rebuild(string $id): void
    {
        $this->connection->transaction(function () use ($id): void {
            $this->connection->table('payrolls')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            $line = $this->stream->replay(id: $id, lock: true);

            $this->connection->table('payrolls')
                ->updateOrInsert(['id' => $id], $this->states->serialize(line: $line));
        });
    }
}
