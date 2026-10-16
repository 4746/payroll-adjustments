<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\PayrollAmountRequest;
use App\Http\Resources\PayrollLineResource;
use App\Payroll\Application\GetAdjustmentHistory;
use App\Payroll\Application\RecalculatePayrollLine;

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
