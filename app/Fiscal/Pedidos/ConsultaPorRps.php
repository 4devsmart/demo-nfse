<?php

declare(strict_types=1);

namespace App\Fiscal\Pedidos;

use InvalidArgumentException;

/**
 * O corpo de POST /v1/nfse/consultas/rps. O RPS e o mesmo par serie/numero que
 * este sistema controla na DPS, por isso a consulta responde "aquele documento
 * que enviei virou nota?" sem depender do id_dps.
 */
final readonly class ConsultaPorRps
{
    private function __construct(
        public string $numero,
        public string $serie,
        public string $tipo,
        public string $codigoDeVerificacao,
    ) {}

    public static function sobreORps(string $numero, string $serie, string $tipo = '1', string $codigoDeVerificacao = ''): self
    {
        if (trim($numero) === '' || trim($serie) === '') {
            throw new InvalidArgumentException(__('A consulta por RPS exige número e série.'));
        }

        return new self(trim($numero), trim($serie), trim($tipo), trim($codigoDeVerificacao));
    }

    /**
     * @return array<string, string>
     */
    public function paraApi(): array
    {
        return array_filter([
            'numero' => $this->numero,
            'serie' => $this->serie,
            'tipo' => $this->tipo,
            'codigo_verificacao' => $this->codigoDeVerificacao,
        ], static fn (string $valor): bool => $valor !== '');
    }
}
