<?php

declare(strict_types=1);

namespace App\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Payroll\Application\GetPayrollLineAmount;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Read the current total through event replay without creating new events.
 * Keep the HTTP amount as decimal text to preserve financial precision.
 */
class GetAmountController extends Controller
{
    public function __construct(private readonly GetPayrollLineAmount $amount) {}

    public function __invoke(string $id): JsonResource
    {
        $amount = $this->amount->handle(id: $id);

        return new JsonResource(resource: [
            'id' => $id,
            'currency' => $amount->currency->value,
            'current_amount' => $amount->toDecimal(),
        ]);
    }
}
