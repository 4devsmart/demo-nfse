<?php

declare(strict_types=1);

namespace App\Fiscal\Certificado;

use Illuminate\Support\Carbon;

/**
 * O que da para ler do proprio .pfx antes de guarda-lo: quem e o titular e ate
 * quando ele vale.
 */
final readonly class DadosDoCertificado
{
    public function __construct(
        public string $titular,
        public Carbon $validoAte,
    ) {}

    public function estaVencido(): bool
    {
        return $this->validoAte->isPast();
    }
}
