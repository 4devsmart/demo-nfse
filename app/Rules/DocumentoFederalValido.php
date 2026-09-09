<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\ValueObjects\DocumentoFederal;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CPF ou CNPJ com digito verificador conferido. A prefeitura recusaria depois;
 * mais barato recusar no cadastro.
 */
final readonly class DocumentoFederalValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (DocumentoFederal::ehValido((string) $value)) {
            return;
        }

        $fail(__('Informe um CPF ou CNPJ válido.'));
    }
}
