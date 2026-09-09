<?php

declare(strict_types=1);

namespace App\Fiscal\Pedidos;

use InvalidArgumentException;

/**
 * Identifica a NFS-e antiga no evento de substituicao. Os valores vem da
 * emissao original, sao os que o provedor devolveu quando autorizou.
 */
final readonly class NotaSubstituida
{
    private function __construct(
        public string $numero,
        public string $serie,
        public string $codigoDeVerificacao,
    ) {}

    public static function identificadaPor(string $numero, string $serie, string $codigoDeVerificacao = ''): self
    {
        if (trim($numero) === '') {
            throw new InvalidArgumentException(__('A substituição precisa do número da NFS-e que será trocada.'));
        }

        return new self(trim($numero), trim($serie), trim($codigoDeVerificacao));
    }

    /**
     * @return array<string, string>
     */
    public function paraApi(): array
    {
        return array_filter([
            'numero' => $this->numero,
            'serie' => $this->serie,
            'codigo_verificacao' => $this->codigoDeVerificacao,
        ], static fn (string $valor): bool => $valor !== '');
    }
}
