<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure;

use App\Payroll\Application\AdjustmentHistory;
use App\Payroll\Application\ConcurrencyConflict;
use App\Payroll\Application\EventStore;
use App\Payroll\Application\PayrollLineNotFound;
use App\Payroll\Domain\Currency;
use App\Payroll\Domain\Money;
use App\Payroll\Domain\PayrollLine;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use stdClass;

/**
 * Current state is loaded in one query; the journal is read only for audit or rebuild.
 * State and immutable events always commit together.
 */
final readonly class MySqlEventStore implements EventStore
{
    const string TABLE = 'payroll_events';

    const string STATE_TABLE = 'payrolls';

    public function __construct(private Connection $connection, private EventSerializer $serializer) {}

    public function load(string $id): PayrollLine
    {
        $row = $this->connection->table(self::STATE_TABLE)->where('id', $id)->first();

        if ($row === null) {
            throw new PayrollLineNotFound(message: "Payroll line [{$id}] was not found.");
        }

        return $this->restore(row: $row);
    }

    public function history(string $id): AdjustmentHistory
    {
        $line = $this->replay(id: $id);

        return new AdjustmentHistory(
            payrollLineId: $id,
            currentAmount: $line->amount(),
            frozenSystemAmount: $line->frozenSystemAmount(),
            adjustments: $line->adjustments(),
        );
    }

    /** @return list<PayrollLine> */
    public function list(int $offset, int $limit): array
    {
        if ($offset < 0 || $limit < 1 || $limit > 101) {
            throw new InvalidArgumentException(message: 'Invalid payroll list bounds.');
        }

        return $this->connection->table(self::STATE_TABLE)
            ->orderBy('id')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map($this->restore(...))
            ->all();
    }

    public function append(PayrollLine $line): void
    {
        if ($line->pendingEvents() === []) {
            return;
        }

        try {
            $this->connection->transaction(function () use ($line): void {
                $state = $this->state(line: $line);

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

    /** Rebuild under a state row lock so a concurrent append cannot be overwritten. */
    public function rebuild(string $id): void
    {
        $this->connection->transaction(function () use ($id): void {
            $this->connection->table(self::STATE_TABLE)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            $line = $this->replay(id: $id, lock: true);
            $this->connection->table(self::STATE_TABLE)
                ->updateOrInsert(['id' => $id], $this->state(line: $line));
        });
    }

    private function replay(string $id, bool $lock = false): PayrollLine
    {
        $query = $this->connection->table(self::TABLE)
            ->where('payroll_id', $id)
            ->orderBy('version');

        if ($lock) {
            $query->lockForUpdate();
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            throw new PayrollLineNotFound(message: "Payroll line [{$id}] was not found.");
        }

        return PayrollLine::reconstitute(id: $id, events: $rows->map($this->serializer->deserialize(...))->all());
    }

    private function restore(stdClass $row): PayrollLine
    {
        $currency = Currency::from(value: $row->currency);

        return PayrollLine::fromState(
            id: $row->id,
            amount: Money::fromMinorUnits(minorUnits: (int) $row->current_amount_minor, currency: $currency),
            frozenSystemAmount: $row->frozen_system_amount_minor === null
                ? null
                : Money::fromMinorUnits(minorUnits: (int) $row->frozen_system_amount_minor, currency: $currency),
            version: (int) $row->version,
        );
    }

    /** @return array{current_amount_minor: int, currency: string, frozen_system_amount_minor: ?int, version: int, updated_at: string} */
    private function state(PayrollLine $line): array
    {
        return [
            'current_amount_minor' => $line->amount()->minorUnits,
            'currency' => $line->amount()->currency->value,
            'frozen_system_amount_minor' => $line->frozenSystemAmount()?->minorUnits,
            'version' => $line->version(),
            'updated_at' => (new DateTimeImmutable(datetime: 'now', timezone: new DateTimeZone(timezone: 'UTC')))->format('Y-m-d H:i:s.u'),
        ];
    }
}
