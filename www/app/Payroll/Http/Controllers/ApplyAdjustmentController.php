<?php

declare(strict_types=1);

namespace App\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Payroll\Application\ApplyManualAdjustment;
use App\Payroll\Application\GetAdjustmentHistory;
use App\Payroll\Http\Requests\ApplyManualAdjustmentRequest;
use App\Payroll\Http\Resources\PayrollLineResource;
use Illuminate\Http\JsonResponse;

/**
 * Submit a new correction without exposing any history-editing operation.
 * Return 201 only after the use case has persisted the adjustment successfully.
 */
class ApplyAdjustmentController extends Controller
{
    public function __construct(
        private readonly ApplyManualAdjustment $adjust,
        private readonly GetAdjustmentHistory $history,
    ) {}

    public function __invoke(ApplyManualAdjustmentRequest $request, string $id): JsonResponse
    {
        $this->adjust->handle($id, $request->money(), $request->validated('comment'));

        return new PayrollLineResource($this->history->handle($id))
            ->response()->setStatusCode(201);
    }
}
