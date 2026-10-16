<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure;

use App\Payroll\Domain\Currency;
use App\Payroll\Domain\InvalidHistory;
use App\Payroll\Domain\ManualAdjustmentApplied;
use App\Payroll\Domain\Money;
use App\Payroll\Domain\PayrollEvent;
use App\Payroll\Domain\PayrollEventType;
use App\Payroll\Domain\PayrollLineCalculated;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use JsonException;
use stdClass;
use TypeError;

/**
 * Explicit boundary between domain facts and the versioned storage format.
 * Stored event names are independent of PHP class names. Unknown formats fail
 * closed instead of guessing values that could alter financial history.
 */
final class EventSerializer
{
    /**
     * Encode cents as integer strings to preserve precision in JSON consumers.
     * Normalize timestamps to UTC while retaining their microseconds.
     *
     * @return array{payroll_id: string, event_id: string, version: int, event_type: string, payload: string, occurred_at: string}
     */
    public function serialize(PayrollEvent $event): array
    {
        $payload = ['schema_version' => 1, 'currency' => $event->amount->currency->value, 'amount_minor' => (string) $event->amount->minorUnits];
        if ($event instanceof ManualAdjustmentApplied) {
            $payload['comment'] = $event->comment;
        }

        return [
            'payroll_id' => $event->payrollId,
            'event_id' => $event->eventId,
            'version' => $event->version,
            'event_type' => match ($event::class) {
                PayrollLineCalculated::class => PayrollEventType::Calculated->value,
                ManualAdjustmentApplied::class => PayrollEventType::ManualAdjustmentApplied->value,
                default => throw new InvalidHistory(message: 'Unknown payroll event type.'),
            },
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $event->occurredAt->setTimezone(new DateTimeZone(timezone: 'UTC'))->format('Y-m-d H:i:s.u'),
        ];
    }

    /**
     * Validate the payload shape and exact values before constructing a fact.
     * The timestamp round-trip check rejects dates PHP would otherwise normalize.
     */
    public function deserialize(stdClass $row): PayrollEvent
    {
        try {
            $eventClass = match (PayrollEventType::tryFrom(value: $row->event_type)) {
                PayrollEventType::Calculated => PayrollLineCalculated::class,
                PayrollEventType::ManualAdjustmentApplied => ManualAdjustmentApplied::class,
                default => throw new InvalidHistory(message: 'Unknown persisted event type.'),
            };
            $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
            $expectedKeys = $eventClass === ManualAdjustmentApplied::class
                ? ['amount_minor', 'comment', 'currency', 'schema_version']
                : ['amount_minor', 'currency', 'schema_version'];
            $keys = is_array($payload) ? array_keys($payload) : [];
            sort($keys);

            if ($keys !== $expectedKeys
                || $payload['schema_version'] !== 1
                || ! Currency::isAllowed(value: $payload['currency'])
                || ! is_string($payload['amount_minor'])
                || preg_match('/^(0|-?[1-9][0-9]*)$/D', $payload['amount_minor']) !== 1
                || ($eventClass === ManualAdjustmentApplied::class && ! is_string($payload['comment']))) {
                throw new InvalidHistory(message: 'Invalid payroll event payload.');
            }

            $minorUnits = filter_var($payload['amount_minor'], FILTER_VALIDATE_INT);
            $version = filter_var($row->version, FILTER_VALIDATE_INT);
            $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $row->occurred_at, new DateTimeZone(timezone: 'UTC'));
            if ($minorUnits === false || $version === false || $time === false || $time->format('Y-m-d H:i:s.u') !== $row->occurred_at) {
                throw new InvalidHistory(message: 'Invalid stored amount, version or timestamp.');
            }

            return new $eventClass(
                payrollId: $row->payroll_id,
                eventId: $row->event_id,
                version: $version,
                occurredAt: $time,
                amount: Money::fromMinorUnits(minorUnits: $minorUnits, currency: Currency::from(value: $payload['currency'])),
                comment: $payload['comment'] ?? null,
            );
        } catch (JsonException|InvalidArgumentException|DomainException|TypeError $exception) {
            throw new InvalidHistory(message: 'Cannot deserialize payroll event: '.$exception->getMessage(), previous: $exception);
        }
    }
}
