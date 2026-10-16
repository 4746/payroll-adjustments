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
            throw new PayrollLineNotFound("Payroll line [{$id}] was not found.");
        }

        return $this->restore($row);
    }

    public function history(string $id): AdjustmentHistory
    {
        $line = $this->replay($id);

        return new AdjustmentHistory($id, $line->amount(), $line->frozenSystemAmount(), $line->adjustments());
    }

    /** @return list<PayrollLine> */
    public function list(int $offset, int $limit): array
    {
        if ($offset < 0 || $limit < 1 || $limit > 101) {
            throw new InvalidArgumentException('Invalid payroll list bounds.');
        }

        return $this->connection->table(self::STATE_TABLE)->orderBy('id')->offset($offset)->limit($limit)
            ->get()->map($this->restore(...))->all();
    }

    public function append(PayrollLine $line): void
    {
        if ($line->pendingEvents() === []) {
            return;
        }

        try {
            $this->connection->transaction(function () use ($line): void {
                $state = $this->state($line);

                if ($line->expectedVersion() === 0) {
                    $this->connection->table(self::STATE_TABLE)->insert(['id' => $line->id, ...$state]);
                } else {
                    $updated = $this->connection->table(self::STATE_TABLE)
                        ->where('id', $line->id)->where('version', $line->expectedVersion())->update($state);

                    if ($updated !== 1) {
                        throw new ConcurrencyConflict("Payroll line [{$line->id}] changed; reload before retrying.");
                    }
                }

                foreach ($line->pendingEvents() as $event) {
                    $this->connection->table(self::TABLE)->insert($this->serializer->serialize($event));
                }
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConcurrencyConflict("Payroll line [{$line->id}] has a conflicting state, stream version or event ID; reload before retrying.", previous: $exception);
        }
    }

    /** Rebuild under a state row lock so a concurrent append cannot be overwritten. */
    public function rebuild(string $id): void
    {
        $this->connection->transaction(function () use ($id): void {
            $this->connection->table(self::STATE_TABLE)->where('id', $id)->lockForUpdate()->first();
            $line = $this->replay($id, true);
            $this->connection->table(self::STATE_TABLE)->updateOrInsert(['id' => $id], $this->state($line));
        });
    }

    private function replay(string $id, bool $lock = false): PayrollLine
    {
        $query = $this->connection->table(self::TABLE)->where('payroll_id', $id)->orderBy('version');

        if ($lock) {
            $query->lockForUpdate();
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            throw new PayrollLineNotFound("Payroll line [{$id}] was not found.");
        }

        return PayrollLine::reconstitute($id, $rows->map($this->serializer->deserialize(...))->all());
    }

    private function restore(stdClass $row): PayrollLine
    {
        $currency = Currency::from($row->currency);

        return PayrollLine::fromState(
            $row->id, Money::fromMinorUnits((int) $row->current_amount_minor, $currency),
            $row->frozen_system_amount_minor === null ? null : Money::fromMinorUnits((int) $row->frozen_system_amount_minor, $currency),
            (int) $row->version,
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
            'updated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
        ];
    }
}
