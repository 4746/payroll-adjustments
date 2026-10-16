<?php

declare(strict_types=1);

namespace App\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Payroll\Application\CreatePayrollLine;
use App\Payroll\Application\GetAdjustmentHistory;
use App\Payroll\Http\Requests\CreatePayrollLineRequest;
use App\Payroll\Http\Resources\PayrollLineResource;
use Illuminate\Http\JsonResponse;

/**
 * Start a payroll event stream from validated HTTP input.
 * Read the persisted stream back so the response uses the same audit view as queries.
 */
class CreateController extends Controller
{
    public function __construct(
        private readonly CreatePayrollLine $create,
        private readonly GetAdjustmentHistory $history,
    ) {}

    public function __invoke(CreatePayrollLineRequest $request): JsonResponse
    {
        $id = $request->validated('id');
        $this->create->handle($id, $request->money());

        return (new PayrollLineResource($this->history->handle($id)))
            ->response()->setStatusCode(201);
    }
}
