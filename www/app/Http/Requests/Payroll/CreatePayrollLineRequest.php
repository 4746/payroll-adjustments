<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

class CreatePayrollLineRequest extends PayrollAmountRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,128}$/D'],
        ];
    }
}
