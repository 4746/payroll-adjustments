<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure;

use App\Payroll\Application\ConcurrencyConflict;
use App\Payroll\Application\PayrollLineNotFound;
use App\Payroll\Application\PayrollRepository;
use App\Payroll\Domain\PayrollLine;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Load current state for domain changes without reading history.
 * State and pending events always commit together with a version check.
 */
final readonly class MySqlPayrollRepository implements PayrollRepository
{
    const string TABLE = 'payroll_events';

    const string STATE_TABLE = 'payrolls';

    public function __construct(
        private Connection $connection,
        private EventSerializer $serializer,
        private PayrollStateMapper $states,
    ) {}

    public function load(string $id): PayrollLine
    {
        $row = $this->connection->table(self::STATE_TABLE)->where('id', $id)->first();

        if ($row === null) {
            throw new PayrollLineNotFound(message: "Payroll line [{$id}] was not found.");
        }

        return $this->states->restore(row: $row);
    }

    public function append(PayrollLine $line): void
    {
        if ($line->pendingEvents() === []) {
            return;
        }

        try {
            $this->connection->transaction(function () use ($line): void {
                $state = $this->states->serialize(line: $line);

                if ($line->expectedVersion() === 0) {
                    $this->connection->table(self::STATE_TABLE)->insert(['id' => $line->id, ...$state]);
                } else {
                    $updated = $this->connection->table(self::STATE_TABLE)
                        ->where('id', $line->id)->where('version', $line->expectedVersion())->update($state);

                    if ($updated !== 1) {
                        throw new ConcurrencyConflict(message: "Payroll line [{$line->id}] changed; reload before retrying.");
                    }
                }

                foreach ($line->pendingEvents() as $event) {
                    $this->connection->table(self::TABLE)->insert($this->serializer->serialize(event: $event));
                }
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConcurrencyConflict(message: "Payroll line [{$line->id}] has a conflicting state, stream version or event ID; reload before retrying.", previous: $exception);
        }
    }
}
