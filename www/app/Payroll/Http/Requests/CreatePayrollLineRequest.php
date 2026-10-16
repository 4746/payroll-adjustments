<?php

declare(strict_types=1);

namespace App\Payroll\Http\Requests;

use App\Payroll\Domain\Currency;
use App\Payroll\Domain\Money;
use Illuminate\Validation\Rule;

class CreatePayrollLineRequest extends PayrollAmountRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,128}$/D'],
            'currency' => ['sometimes', 'required', 'string', Rule::enum(Currency::class)],
        ];
    }

    public function money(): Money
    {
        return Money::fromDecimal($this->validated('amount'), Currency::from($this->validated('currency', Currency::Usd->value)));
    }
}
