<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure;

use App\Payroll\Domain\Currency;
use App\Payroll\Domain\Money;
use App\Payroll\Domain\PayrollLine;
use DateTimeImmutable;
use DateTimeZone;
use stdClass;

/** Shared database representation of current state for writes, reads and rebuilds. */
final class PayrollStateMapper
{
    public function restore(stdClass $row): PayrollLine
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
    public function serialize(PayrollLine $line): array
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
