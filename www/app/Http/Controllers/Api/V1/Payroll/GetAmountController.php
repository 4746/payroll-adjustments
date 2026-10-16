<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payroll;

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
        return new JsonResource([
            'id' => $id,
            'currency' => 'USD',
            'current_amount' => $this->amount->handle($id)->toDecimal(),
        ]);
    }
}
