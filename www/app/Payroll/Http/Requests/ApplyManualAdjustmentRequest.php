<?php

declare(strict_types=1);

namespace App\Payroll\Http\Requests;

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
