<?php

declare(strict_types=1);

namespace App\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Payroll\Application\GetAdjustmentHistory;
use App\Payroll\Http\Resources\PayrollLineResource;

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
