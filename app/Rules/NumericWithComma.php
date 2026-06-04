<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NumericWithComma implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (str_contains($value, ',')) {
            if (! preg_match('/^\d{1,3}(,\d{3})*(\.\d\d*)?$/', $value)) {
                $fail(__('Number format is invalid'));
            }
        } else {
            if (! preg_match('/^\d+(\.\d\d*)?$/', $value)) {
                $fail(__('Number format is invalid'));
            }
        }
    }
}
