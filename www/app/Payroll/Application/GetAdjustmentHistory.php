<?php

declare(strict_types=1);

namespace App\Payroll\Application;

final readonly class GetAdjustmentHistory
{
    public function __construct(private EventStore $events) {}

    public function handle(string $id): AdjustmentHistory
    {
        $line = $this->events->load($id);

        return new AdjustmentHistory($line->id, $line->amount(), $line->frozenSystemAmount(), $line->adjustments());
    }
}
