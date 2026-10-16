<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Payroll\Domain\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

/**
 * Validate the HTTP representation while keeping monetary rules in Money.
 * Amounts must be JSON strings so the client cannot lose precision through floats.
 */
class PayrollAmountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'amount' => [
                'bail', 'required', 'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        Money::fromDecimal($value);
                    } catch (InvalidArgumentException $exception) {
                        $fail($exception->getMessage());
                    }
                },
            ],
        ];
    }

    public function money(): Money
    {
        return Money::fromDecimal($this->validated('amount'));
    }
}
