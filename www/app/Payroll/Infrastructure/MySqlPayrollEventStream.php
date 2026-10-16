<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure;

use App\Payroll\Application\PayrollLineNotFound;
use App\Payroll\Domain\PayrollLine;
use Illuminate\Database\Connection;

/** Replay ordered journal facts for audit or a rebuild inside its transaction. */
final readonly class MySqlPayrollEventStream
{
    public function __construct(private Connection $connection, private EventSerializer $serializer) {}

    public function replay(string $id, bool $lock = false): PayrollLine
    {
        $query = $this->connection->table('payroll_events')->where('payroll_id', $id)->orderBy('version');

        if ($lock) {
            $query->lockForUpdate();
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            throw new PayrollLineNotFound(message: "Payroll line [{$id}] was not found.");
        }

        return PayrollLine::reconstitute(id: $id, events: $rows->map($this->serializer->deserialize(...))->all());
    }
}
