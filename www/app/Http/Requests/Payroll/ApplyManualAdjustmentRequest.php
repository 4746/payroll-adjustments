<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

class ApplyManualAdjustmentRequest extends PayrollAmountRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'comment' => ['required', 'string'],
        ];
    }
}
