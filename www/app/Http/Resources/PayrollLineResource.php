<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Payroll\Application\AdjustmentHistory;
use App\Payroll\Domain\ManualAdjustmentApplied;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Present a replayed audit snapshot without exposing persistence payloads.
 * Money stays decimal text and event timestamps are explicitly UTC.
 */
class PayrollLineResource extends JsonResource
{
    /** @return array{id: string, currency: string, current_amount: string, frozen_system_amount: ?string, adjustments: list<array{event_id: string, version: int, amount: string, comment: string, occurred_at: string}>} */
    public function toArray(Request $request): array
    {
        /** @var AdjustmentHistory $history */
        $history = $this->resource;

        return [
            'id' => $history->payrollLineId,
            'currency' => 'USD',
            'current_amount' => $history->currentAmount->toDecimal(),
            'frozen_system_amount' => $history->frozenSystemAmount?->toDecimal(),
            'adjustments' => array_map(fn (ManualAdjustmentApplied $event): array => [
                'event_id' => $event->eventId,
                'version' => $event->version,
                'amount' => $event->amount->toDecimal(),
                'comment' => $event->comment,
                'occurred_at' => $event->occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z'),
            ], $history->adjustments),
        ];
    }
}
