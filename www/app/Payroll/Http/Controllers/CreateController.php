<?php

declare(strict_types=1);

namespace App\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Payroll\Application\CreatePayrollLine;
use App\Payroll\Http\Requests\CreatePayrollLineRequest;
use App\Payroll\Http\Resources\PayrollLineResource;
use Illuminate\Http\JsonResponse;

/**
 * Start a payroll event stream from validated HTTP input.
 * Return the committed operation state without loading its historical audit.
 */
class CreateController extends Controller
{
    public function __construct(private readonly CreatePayrollLine $create) {}

    public function __invoke(CreatePayrollLineRequest $request): JsonResponse
    {
        $id = $request->validated('id');
        $line = $this->create->handle($id, $request->money());

        return PayrollLineResource::fromLine($line)
            ->response()->setStatusCode(201);
    }
}
