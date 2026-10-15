<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure;

use App\Payroll\Application\ConcurrencyConflict;
use App\Payroll\Application\EventStore;
use App\Payroll\Application\PayrollLineNotFound;
use App\Payroll\Domain\PayrollLine;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;

final readonly class MySqlEventStore implements EventStore
{
    public function __construct(private Connection $connection, private EventSerializer $serializer) {}

    public function load(string $id): PayrollLine
    {
        $rows = $this->connection->table('payroll_events')
            ->where('aggregate_id', $id)->orderBy('version')->get();

        if ($rows->isEmpty()) {
            throw new PayrollLineNotFound("Payroll line [{$id}] was not found.");
        }

        return PayrollLine::reconstitute($id, $rows->map($this->serializer->deserialize(...))->all());
    }

    public function append(PayrollLine $line): void
    {
        if ($line->pendingEvents() === []) {
            return;
        }

        try {
            $this->connection->transaction(function () use ($line): void {
                $storedVersion = (int) $this->connection->table('payroll_events')
                    ->where('aggregate_id', $line->id)->max('version');

                if ($storedVersion !== $line->expectedVersion()) {
                    throw new ConcurrencyConflict("Payroll line [{$line->id}] changed; reload before retrying.");
                }

                foreach ($line->pendingEvents() as $event) {
                    $this->connection->table('payroll_events')->insert($this->serializer->serialize($event));
                }
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConcurrencyConflict("Payroll line [{$line->id}] has a conflicting stream version or event ID; reload before retrying.", previous: $exception);
        }
    }
}
