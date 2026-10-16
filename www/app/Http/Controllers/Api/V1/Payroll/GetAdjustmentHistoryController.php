<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Resources\PayrollLineResource;
use App\Payroll\Application\GetAdjustmentHistory;

/**
 * Present the frozen baseline and ordered adjustments from one replayed snapshot.
 * The resource handles HTTP serialization; the query owns reconstruction.
 */
class GetAdjustmentHistoryController extends Controller
{
    public function __construct(private readonly GetAdjustmentHistory $history) {}

    public function __invoke(string $id): PayrollLineResource
    {
        return new PayrollLineResource($this->history->handle($id));
    }
}
