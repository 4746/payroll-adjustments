<?php

declare(strict_types=1);

namespace App\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Payroll\Application\GetPayrollLines;
use App\Payroll\Domain\PayrollLine;
use App\Payroll\Http\Requests\ListPayrollLinesRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class ListController extends Controller
{
    public function __construct(private readonly GetPayrollLines $lines) {}

    /**
     * Return current payroll states under "data" and pagination under "meta"
     * (page, per_page, has_more), without loading event history.
     */
    public function __invoke(ListPayrollLinesRequest $request): AnonymousResourceCollection
    {
        $page = $request->safe()->integer('page', 1);
        $perPage = $request->safe()->integer('per_page', 100);
        $lines = $this->lines->handle($page, $perPage);
        $hasMore = count($lines) > $perPage;
        $items = array_map(fn (PayrollLine $line): array => [
            'id' => $line->id,
            'currency' => $line->amount()->currency->value,
            'current_amount' => $line->amount()->toDecimal(),
            'frozen_system_amount' => $line->frozenSystemAmount()?->toDecimal(),
            'version' => $line->version(),
        ], array_slice($lines, 0, $perPage));

        return JsonResource::collection($items)->additional([
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'has_more' => $hasMore,
            ],
        ]);
    }
}
