<?php

declare(strict_types=1);

namespace App\Payroll\Application;

/** Restore persisted current state from authoritative history without changing events. */
interface PayrollStateRebuilder
{
    public function rebuild(string $id): void;
}
