<?php

declare(strict_types=1);

namespace App\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Payroll\Application\RecalculatePayrollLine;
use App\Payroll\Http\Requests\PayrollAmountRequest;
use App\Payroll\Http\Resources\PayrollLineResource;

/**
 * Delegate automatic recalculation to the application use case.
 * The aggregate decides whether manual override makes this operation a no-op.
 */
class RecalculateController extends Controller
{
    public function __construct(private readonly RecalculatePayrollLine $recalculate) {}

    public function __invoke(PayrollAmountRequest $request, string $id): PayrollLineResource
    {
        $line = $this->recalculate->handle($id, $request->validated('amount'));

        return PayrollLineResource::fromLine($line);
    }
}
