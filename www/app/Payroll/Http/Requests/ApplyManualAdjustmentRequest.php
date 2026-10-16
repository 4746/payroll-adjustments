<?php

declare(strict_types=1);

namespace App\Payroll\Http\Requests;

use App\Payroll\Domain\ManualAdjustmentApplied;

class ApplyManualAdjustmentRequest extends PayrollAmountRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'comment' => ['required', 'string', 'max:'.ManualAdjustmentApplied::MAX_COMMENT_LENGTH],
        ];
    }
}
