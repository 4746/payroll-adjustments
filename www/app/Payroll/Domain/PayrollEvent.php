<?php

declare(strict_types=1);

namespace App\Payroll\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable payroll fact with identity, stream order and occurrence time.
 * Version determines application order; timestamps are traceability metadata.
 * Validation also runs during deserialization so replay cannot bypass the
 * non-zero adjustment and mandatory comment policies.
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
        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $payrollId) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $eventId) !== 1
            || $version < 1) {
            throw new InvalidArgumentException('Invalid event identity or version.');
        }

        if ($this instanceof ManualAdjustmentApplied) {
            if ($amount->minorUnits === 0 || $comment === null || preg_match('/\\S/u', $comment) !== 1) {
                throw new InvalidAdjustment('A non-zero adjustment and non-blank UTF-8 comment are required.');
            }
        } elseif ($comment !== null) {
            throw new InvalidArgumentException('Calculation events cannot contain adjustment comments.');
        }
    }
}
