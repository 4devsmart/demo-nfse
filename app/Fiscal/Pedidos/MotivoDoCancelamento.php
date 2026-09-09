<?php

declare(strict_types=1);

namespace App\Fiscal\Pedidos;

use InvalidArgumentException;

/**
 * `evento` do POST /v1/nfse/eventos/cancelamento. No Padrao Nacional o codigo
 * default e 1 (erro na emissao).
 */
final readonly class MotivoDoCancelamento
{
    private const TAMANHO_MINIMO = 15;

    private function __construct(
        public string $codigo,
        public string $descricao,
    ) {}

    public static function descrito(string $descricao, string $codigo = '1'): self
    {
        $limpa = trim($descricao);

        if (mb_strlen($limpa) < self::TAMANHO_MINIMO) {
            throw new InvalidArgumentException(__(
                'O motivo do cancelamento precisa de pelo menos :minimo caracteres.',
                ['minimo' => self::TAMANHO_MINIMO],
            ));
        }

        return new self($codigo, $limpa);
    }

    /**
     * @return array<string, string>
     */
    public function paraApi(): array
    {
        return [
            'codigo' => $this->codigo,
            'motivo' => $this->descricao,
        ];
    }
}
