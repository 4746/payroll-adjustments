<?php

declare(strict_types=1);

namespace App\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Payroll\Application\ApplyManualAdjustment;
use App\Payroll\Http\Requests\ApplyManualAdjustmentRequest;
use App\Payroll\Http\Resources\PayrollLineResource;
use Illuminate\Http\JsonResponse;

/**
 * Submit a new correction without exposing any history-editing operation.
 * Return 201 only after the use case has persisted the adjustment successfully.
 */
class ApplyAdjustmentController extends Controller
{
    public function __construct(private readonly ApplyManualAdjustment $adjust) {}

    public function __invoke(ApplyManualAdjustmentRequest $request, string $id): JsonResponse
    {
        $line = $this->adjust->handle($id, $request->validated('amount'), $request->validated('comment'));

        return PayrollLineResource::fromLine($line)
            ->response()->setStatusCode(201);
    }
}
