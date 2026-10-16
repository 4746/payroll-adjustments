<?php

declare(strict_types=1);

namespace App\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Payroll\Application\GetAdjustmentHistory;
use App\Payroll\Application\RecalculatePayrollLine;
use App\Payroll\Http\Requests\PayrollAmountRequest;
use App\Payroll\Http\Resources\PayrollLineResource;

/**
 * Delegate automatic recalculation to the application use case.
 * The aggregate decides whether manual override makes this operation a no-op.
 */
class RecalculateController extends Controller
{
    public function __construct(
        private readonly RecalculatePayrollLine $recalculate,
        private readonly GetAdjustmentHistory $history,
    ) {}

    public function __invoke(PayrollAmountRequest $request, string $id): PayrollLineResource
    {
        $this->recalculate->handle($id, $request->money());

        return new PayrollLineResource($this->history->handle($id));
    }
}
