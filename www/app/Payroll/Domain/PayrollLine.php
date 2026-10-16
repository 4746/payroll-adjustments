<?php

declare(strict_types=1);

namespace App\Payroll\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Consistency boundary for one payroll line and its ordered event stream.
 *
 * Calculations replace the amount until the first manual adjustment. That
 * adjustment freezes the latest system amount permanently; thereafter the
 * current amount is the frozen baseline plus every accepted adjustment.
 * New operations and replay share apply() so both enforce the same invariants.
 */
final class PayrollLine
{
    private Money $amount;

    /**
     * Null means automatic calculations are still allowed. Once set, this also
     * acts as the permanent override marker, even when adjustments sum to zero.
     */
    private ?Money $frozenSystemAmount = null;

    private int $version = 0;

    /** @var list<ManualAdjustmentApplied> */
    private array $adjustments = [];

    /** @var list<PayrollEvent> */
    private array $pendingEvents = [];

    /** @var array<string, true> */
    private array $eventIds = [];

    private function __construct(public readonly string $id)
    {
        if (! PayrollIdValidator::isValid(value: $id)) {
            throw new InvalidArgumentException(message: 'Payroll line IDs must contain 1-128 ASCII letters, digits, underscores or hyphens.');
        }
    }

    /**
     * Start a new stream with a calculation, pending persistence at version 1.
     */
    public static function create(string $id, Money $amount): self
    {
        $line = new self(id: $id);
        $line->record(event: $line->calculation(amount: $amount));

        return $line;
    }

    /**
     * Restore persisted facts without recording them as new work to save.
     * Stream versions define application order; timestamps need not be unique.
     *
     * @param  list<PayrollEvent>  $events
     *
     * @throws InvalidHistory If the stream is empty or violates domain invariants.
     */
    public static function reconstitute(string $id, array $events): self
    {
        if ($events === []) {
            throw new InvalidHistory(message: 'A payroll line must start with a calculation.');
        }

        $line = new self(id: $id);
        foreach ($events as $event) {
            $line->apply(event: $event);
        }

        return $line;
    }

    /**
     * Ignore recalculations after manual override, including event creation.
     * The ignored value must never replace either the total or frozen baseline.
     */
    public function recalculate(Money $amount): void
    {
        if ($amount->currency !== $this->amount->currency) {
            throw new InvalidAdjustment(message: 'A payroll line cannot change currency.');
        }

        if ($this->frozenSystemAmount !== null) {
            return;
        }

        $this->record(event: $this->calculation(amount: $amount));
    }

    /**
     * Append a signed correction with its original explanation.
     * Mistakes require another compensating adjustment; history is never edited.
     *
     * @throws InvalidAdjustment If the delta is zero or the comment is blank/invalid UTF-8.
     * @throws \OverflowException If the resulting total exceeds integer capacity.
     */
    public function applyAdjustment(Money $amount, string $comment): void
    {
        if ($amount->currency !== $this->amount->currency) {
            throw new InvalidAdjustment(message: 'An adjustment must use the payroll line currency.');
        }

        $this->record(event: new ManualAdjustmentApplied(
            payrollId: $this->id,
            eventId: bin2hex(random_bytes(16)),
            version: $this->version + 1,
            occurredAt: new DateTimeImmutable(datetime: 'now', timezone: new DateTimeZone(timezone: 'UTC')),
            amount: $amount,
            comment: $comment,
        ));
    }

    /** Restore the current state without loading the historical audit. */
    public static function fromState(string $id, Money $amount, ?Money $frozenSystemAmount, int $version): self
    {
        if ($version < 1 || ($frozenSystemAmount !== null && ($version < 2 || $frozenSystemAmount->currency !== $amount->currency))) {
            throw new InvalidHistory(message: 'Invalid persisted payroll state.');
        }

        $line = new self(id: $id);
        $line->amount = $amount;
        $line->frozenSystemAmount = $frozenSystemAmount;
        $line->version = $version;

        return $line;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function frozenSystemAmount(): ?Money
    {
        return $this->frozenSystemAmount;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * Version observed when this unit of work was loaded (zero for a new line).
     * The store compares it with persisted history to detect competing writes.
     */
    public function expectedVersion(): int
    {
        return $this->version - count($this->pendingEvents);
    }

    /**
     * Adjustment facts observed during replay or this unit of work.
     * State-only loads do not include historical adjustments; query audit separately.
     *
     * @return list<ManualAdjustmentApplied>
     */
    public function adjustments(): array
    {
        return $this->adjustments;
    }

    /**
     * New facts to append atomically, never events restored through replay.
     * Append this unit of work once, then reload before the next operation.
     * The buffer is retained so a failed write can be retried without losing work.
     *
     * @return list<PayrollEvent>
     */
    public function pendingEvents(): array
    {
        return $this->pendingEvents;
    }

    private function calculation(Money $amount): PayrollLineCalculated
    {
        return new PayrollLineCalculated(
            payrollId: $this->id,
            eventId: bin2hex(random_bytes(16)),
            version: $this->version + 1,
            occurredAt: new DateTimeImmutable(datetime: 'now', timezone: new DateTimeZone(timezone: 'UTC')),
            amount: $amount,
        );
    }

    /**
     * Accept the transition before adding it to the persistence buffer.
     * A rejected operation must not leave an event waiting to be saved.
     */
    private function record(PayrollEvent $event): void
    {
        $this->apply(event: $event);
        $this->pendingEvents[] = $event;
    }

    /**
     * Shared transition function for new events and historical replay.
     * Calculations after override indicate corrupt history: live recalculations
     * are ignored before an event is created, so such a fact cannot be valid.
     * Adjustment arithmetic completes before any state changes, ensuring an
     * overflow cannot freeze the baseline or partially apply the adjustment.
     */
    private function apply(PayrollEvent $event): void
    {
        if ($event->payrollId !== $this->id || $event->version !== $this->version + 1 || isset($this->eventIds[$event->eventId])) {
            throw new InvalidHistory(message: 'Event stream identity, order or uniqueness is invalid.');
        }

        if ($this->version > 0 && $event->amount->currency !== $this->amount->currency) {
            throw new InvalidHistory(message: 'All payroll line events must use the initial currency.');
        }

        if ($event instanceof PayrollLineCalculated) {
            if ($this->frozenSystemAmount !== null) {
                throw new InvalidHistory(message: 'A frozen payroll line cannot contain later calculations.');
            }
            $this->amount = $event->amount;
        } elseif ($event instanceof ManualAdjustmentApplied) {
            if ($this->version === 0) {
                throw new InvalidHistory(message: 'An adjustment requires an initial calculation.');
            }
            $newAmount = $this->amount->add(other: $event->amount);
            $this->frozenSystemAmount ??= $this->amount;
            $this->amount = $newAmount;
            $this->adjustments[] = $event;
        } else {
            throw new InvalidHistory(message: 'Unknown payroll event.');
        }

        $this->version = $event->version;
        $this->eventIds[$event->eventId] = true;
    }
}
