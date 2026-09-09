<?php

declare(strict_types=1);

namespace App\Fiscal\Dps;

use App\Domain\ValueObjects\CodigoIbge;

/**
 * Endereco nacional. Quando ha `cMun`, a API preenche o pais (1058) sozinha.
 */
final readonly class Endereco
{
    public function __construct(
        public CodigoIbge $municipio,
        public string $uf,
        public string $cep,
        public string $logradouro,
        public string $numero,
        public string $bairro,
        public string $complemento = '',
    ) {}

    /**
     * @return array<string, string>
     */
    public function paraApi(): array
    {
        return [
            'cMun' => (string) $this->municipio,
            'UF' => strtoupper($this->uf),
            'CEP' => preg_replace('/\D/', '', $this->cep) ?? '',
            'logradouro' => $this->logradouro,
            'numero' => $this->numero,
            'complemento' => $this->complemento,
            'bairro' => $this->bairro,
        ];
    }
}
