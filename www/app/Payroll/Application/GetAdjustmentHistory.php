<?php

declare(strict_types=1);

namespace App\Payroll\Application;

final readonly class GetAdjustmentHistory
{
    public function __construct(private EventStore $events) {}

    public function handle(string $id): AdjustmentHistory
    {
        return $this->events->history(id: $id);
    }
}
