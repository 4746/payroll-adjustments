<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PayrollDemoTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_demo_displays_each_step_and_the_final_persisted_audit_history(): void
    {
        $this->artisan('payroll:demo', ['--id' => 'demo-test'])
            ->expectsOutput('Payroll line: demo-test')
            ->expectsOutput('1. Create: $1000.00')
            ->expectsOutput('2. Recalculate: $1050.00')
            ->expectsOutput('3. Unpaid leave: $1004.45')
            ->expectsOutput('4. Recalculate (ignored): $1004.45')
            ->expectsOutput('5. Bonus: $1104.55')
            ->expectsOutput('6. Correction A: $1104.45')
            ->expectsOutput('7. Correction B: $1104.25')
            ->expectsOutput('8. Reverse correction B: $1104.45')
            ->expectsOutput('Frozen system amount: $1050.00')
            ->expectsOutput('Adjustments: 5')
            ->expectsOutput('Final amount: $1104.45')
            ->assertSuccessful();

        $this->assertDatabaseCount('payroll_events', 7);
        $this->assertDatabaseHas('payroll_events', ['aggregate_id' => 'demo-test', 'version' => 7]);
    }

    public function test_duplicate_demo_id_returns_failure_without_appending_or_overwriting_events(): void
    {
        $this->artisan('payroll:demo', ['--id' => 'demo-test'])->assertSuccessful();
        $this->artisan('payroll:demo', ['--id' => 'demo-test'])->assertFailed();
        $this->assertDatabaseCount('payroll_events', 7);
    }

    public function test_invalid_demo_id_returns_a_clear_failure(): void
    {
        $this->artisan('payroll:demo', ['--id' => 'invalid id'])
            ->expectsOutput('Payroll line IDs must contain 1-128 ASCII letters, digits, underscores or hyphens.')
            ->assertFailed();
        $this->assertDatabaseCount('payroll_events', 0);
    }
}
