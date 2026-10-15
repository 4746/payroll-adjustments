<?php

declare(strict_types=1);

namespace App\Payroll\Application;

use App\Payroll\Domain\Money;

final readonly class ApplyManualAdjustment
{
    public function __construct(private EventStore $events) {}

    public function handle(string $id, Money $amount, string $comment): void
    {
        $line = $this->events->load($id);
        $line->applyAdjustment($amount, $comment);
        $this->events->append($line);
    }
}
