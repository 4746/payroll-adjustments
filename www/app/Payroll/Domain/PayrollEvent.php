<?php

declare(strict_types=1);

namespace App\Payroll\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable payroll fact with identity, stream order and occurrence time.
 * Version determines application order; timestamps are traceability metadata.
 * Each event validates its payload during construction, including deserialization.
 */
abstract readonly class PayrollEvent
{
    final public function __construct(
        public string $payrollId,
        public string $eventId,
        public int $version,
        public DateTimeImmutable $occurredAt,
        public Money $amount,
        public ?string $comment = null,
    ) {
        if (! PayrollIdValidator::isValid(value: $payrollId)
            || ! PayrollEventIdValidator::isValid(value: $eventId)
            || $version < 1) {
            throw new InvalidArgumentException(message: 'Invalid event identity or version.');
        }

        $this->validatePayload();
    }

    /** Enforce the concrete event's rules after its promoted properties are initialized. */
    abstract protected function validatePayload(): void;
}
