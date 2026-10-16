<?php

declare(strict_types=1);

namespace App\Payroll\Domain;

/**
 * A signed delta with its original explanation, preserved as a separate fact.
 * The first such fact freezes the baseline; compensation adds another fact.
 */
final readonly class ManualAdjustmentApplied extends PayrollEvent
{
    /** Shared HTTP and domain limit, measured in Unicode characters rather than bytes. */
    public const int MAX_COMMENT_LENGTH = 2000;

    protected function validatePayload(): void
    {
        if ($this->amount->minorUnits === 0 || $this->comment === null || preg_match('/\\S/u', $this->comment) !== 1) {
            throw new InvalidAdjustment(message: 'A non-zero adjustment and non-blank UTF-8 comment are required.');
        }

        if (mb_strlen($this->comment, 'UTF-8') > self::MAX_COMMENT_LENGTH) {
            throw new InvalidAdjustment(message: 'Adjustment comments must not exceed '.self::MAX_COMMENT_LENGTH.' characters.');
        }
    }
}
