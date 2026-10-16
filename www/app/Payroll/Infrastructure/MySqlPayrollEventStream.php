<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure;

use App\Payroll\Application\PayrollLineNotFound;
use App\Payroll\Domain\PayrollEvent;
use App\Payroll\Domain\PayrollLine;
use Illuminate\Database\Connection;

/** Replay ordered journal facts for audit or a rebuild inside its transaction. */
final readonly class MySqlPayrollEventStream
{
    public function __construct(private Connection $connection, private EventSerializer $serializer) {}

    public function replay(string $id, bool $lock = false): PayrollLine
    {
        return PayrollLine::reconstitute(id: $id, events: $this->read(id: $id, lock: $lock));
    }

    /** @return list<PayrollEvent> Ordered facts; callers replay them to validate stream transitions. */
    public function read(string $id, bool $lock = false): array
    {
        $query = $this->connection->table('payroll_events')
            ->where('payroll_id', $id)
            ->orderBy('version');

        if ($lock) {
            $query->lockForUpdate();
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            throw new PayrollLineNotFound(message: "Payroll line [{$id}] was not found.");
        }

        return $rows->map($this->serializer->deserialize(...))->all();
    }
}
