<?php

declare(strict_types=1);

namespace App\Payroll\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class PayrollLine
{
    private Money $amount;

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
        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Payroll line IDs must contain 1-128 ASCII letters, digits, underscores or hyphens.');
        }
    }

    public static function create(string $id, Money $amount): self
    {
        $line = new self($id);
        $line->record($line->calculation($amount));

        return $line;
    }

    /** @param list<PayrollEvent> $events */
    public static function reconstitute(string $id, array $events): self
    {
        if ($events === []) {
            throw new InvalidHistory('A payroll line must start with a calculation.');
        }

        $line = new self($id);
        foreach ($events as $event) {
            $line->apply($event);
        }

        return $line;
    }

    public function recalculate(Money $amount): void
    {
        if ($this->frozenSystemAmount !== null) {
            return;
        }

        $this->record($this->calculation($amount));
    }

    public function applyAdjustment(Money $amount, string $comment): void
    {
        $this->record(new ManualAdjustmentApplied(
            $this->id, bin2hex(random_bytes(16)), $this->version + 1,
            new DateTimeImmutable('now', new DateTimeZone('UTC')), $amount, $comment,
        ));
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

    public function expectedVersion(): int
    {
        return $this->version - count($this->pendingEvents);
    }

    /** @return list<ManualAdjustmentApplied> */
    public function adjustments(): array
    {
        return $this->adjustments;
    }

    /** @return list<PayrollEvent> */
    public function pendingEvents(): array
    {
        return $this->pendingEvents;
    }

    private function calculation(Money $amount): PayrollLineCalculated
    {
        return new PayrollLineCalculated(
            $this->id, bin2hex(random_bytes(16)), $this->version + 1,
            new DateTimeImmutable('now', new DateTimeZone('UTC')), $amount,
        );
    }

    private function record(PayrollEvent $event): void
    {
        $this->apply($event);
        $this->pendingEvents[] = $event;
    }

    private function apply(PayrollEvent $event): void
    {
        if ($event->aggregateId !== $this->id || $event->version !== $this->version + 1 || isset($this->eventIds[$event->eventId])) {
            throw new InvalidHistory('Event stream identity, order or uniqueness is invalid.');
        }

        if ($event instanceof PayrollLineCalculated) {
            if ($this->frozenSystemAmount !== null) {
                throw new InvalidHistory('A frozen payroll line cannot contain later calculations.');
            }
            $this->amount = $event->amount;
        } elseif ($event instanceof ManualAdjustmentApplied) {
            if ($this->version === 0) {
                throw new InvalidHistory('An adjustment requires an initial calculation.');
            }
            $newAmount = $this->amount->add($event->amount);
            $this->frozenSystemAmount ??= $this->amount;
            $this->amount = $newAmount;
            $this->adjustments[] = $event;
        } else {
            throw new InvalidHistory('Unknown payroll event.');
        }

        $this->version = $event->version;
        $this->eventIds[$event->eventId] = true;
    }
}
