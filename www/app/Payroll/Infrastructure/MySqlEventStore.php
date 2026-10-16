<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure;

use App\Payroll\Application\ConcurrencyConflict;
use App\Payroll\Application\EventStore;
use App\Payroll\Application\PayrollLineNotFound;
use App\Payroll\Domain\PayrollLine;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Stores facts only; balances and audit views are derived by replay.
 * Append-only access is enforced by this API, not against privileged SQL access.
 */
final readonly class MySqlEventStore implements EventStore
{
    const string TABLE = 'payroll_events';

    public function __construct(private Connection $connection, private EventSerializer $serializer) {}

    public function load(string $id): PayrollLine
    {
        $rows = $this->connection->table(self::TABLE)
            ->where('aggregate_id', $id)->orderBy('version')->get();

        if ($rows->isEmpty()) {
            throw new PayrollLineNotFound("Payroll line [{$id}] was not found.");
        }

        return PayrollLine::reconstitute($id, $rows->map($this->serializer->deserialize(...))->all());
    }

    /**
     * Persist all pending facts within one transaction.
     * The version comparison catches already stale writers; the unique stream
     * version constraint catches competitors committing after that comparison.
     * Pending events remain on the aggregate so failed batches can be retried.
     */
    public function append(PayrollLine $line): void
    {
        if ($line->pendingEvents() === []) {
            return;
        }

        try {
            $this->connection->transaction(function () use ($line): void {
                $storedVersion = (int) $this->connection->table(self::TABLE)
                    ->where('aggregate_id', $line->id)->max('version');

                if ($storedVersion !== $line->expectedVersion()) {
                    throw new ConcurrencyConflict("Payroll line [{$line->id}] changed; reload before retrying.");
                }

                foreach ($line->pendingEvents() as $event) {
                    $this->connection->table(self::TABLE)->insert($this->serializer->serialize($event));
                }
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConcurrencyConflict("Payroll line [{$line->id}] has a conflicting stream version or event ID; reload before retrying.", previous: $exception);
        }
    }
}
