<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure;

use App\Payroll\Domain\InvalidHistory;
use App\Payroll\Domain\ManualAdjustmentApplied;
use App\Payroll\Domain\Money;
use App\Payroll\Domain\PayrollEvent;
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
     * @return array{aggregate_id: string, event_id: string, version: int, event_type: string, payload: string, occurred_at: string}
     */
    public function serialize(PayrollEvent $event): array
    {
        $payload = ['schema_version' => 1, 'currency' => 'USD', 'amount_minor' => (string) $event->amount->minorUnits];
        if ($event instanceof ManualAdjustmentApplied) {
            $payload['comment'] = $event->comment;
        }

        return [
            'aggregate_id' => $event->aggregateId,
            'event_id' => $event->eventId,
            'version' => $event->version,
            'event_type' => match ($event::class) {
                PayrollLineCalculated::class => 'payroll_line_calculated.v1',
                ManualAdjustmentApplied::class => 'manual_adjustment_applied.v1',
                default => throw new InvalidHistory('Unknown payroll event type.'),
            },
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $event->occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        ];
    }

    /**
     * Validate the payload shape and exact values before constructing a fact.
     * The timestamp round-trip check rejects dates PHP would otherwise normalize.
     */
    public function deserialize(stdClass $row): PayrollEvent
    {
        try {
            $eventClass = match ($row->event_type) {
                'payroll_line_calculated.v1' => PayrollLineCalculated::class,
                'manual_adjustment_applied.v1' => ManualAdjustmentApplied::class,
                default => throw new InvalidHistory('Unknown persisted event type.'),
            };
            $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
            $expectedKeys = $eventClass === ManualAdjustmentApplied::class
                ? ['amount_minor', 'comment', 'currency', 'schema_version']
                : ['amount_minor', 'currency', 'schema_version'];
            $keys = is_array($payload) ? array_keys($payload) : [];
            sort($keys);

            if ($keys !== $expectedKeys
                || $payload['schema_version'] !== 1
                || $payload['currency'] !== 'USD'
                || ! is_string($payload['amount_minor'])
                || preg_match('/^(0|-?[1-9][0-9]*)$/D', $payload['amount_minor']) !== 1
                || ($eventClass === ManualAdjustmentApplied::class && ! is_string($payload['comment']))) {
                throw new InvalidHistory('Invalid payroll event payload.');
            }

            $minorUnits = filter_var($payload['amount_minor'], FILTER_VALIDATE_INT);
            $version = filter_var($row->version, FILTER_VALIDATE_INT);
            $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $row->occurred_at, new DateTimeZone('UTC'));
            if ($minorUnits === false || $version === false || $time === false || $time->format('Y-m-d H:i:s.u') !== $row->occurred_at) {
                throw new InvalidHistory('Invalid stored amount, version or timestamp.');
            }

            return new $eventClass(
                $row->aggregate_id, $row->event_id, $version, $time,
                Money::fromMinorUnits($minorUnits), $payload['comment'] ?? null,
            );
        } catch (JsonException|InvalidArgumentException|DomainException|TypeError $exception) {
            throw new InvalidHistory('Cannot deserialize payroll event: '.$exception->getMessage(), previous: $exception);
        }
    }
}
