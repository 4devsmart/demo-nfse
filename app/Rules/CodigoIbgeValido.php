<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\ValueObjects\CodigoIbge;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final readonly class CodigoIbgeValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (CodigoIbge::ehValido((string) $value)) {
            return;
        }

        $fail(__('O código IBGE precisa ter 7 dígitos.'));
    }
}
