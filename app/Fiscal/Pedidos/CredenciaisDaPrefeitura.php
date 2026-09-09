<?php

declare(strict_types=1);

namespace App\Fiscal\Pedidos;

use SensitiveParameter;

/**
 * Login de webservice exigido por provedores fora do Padrao Nacional. Como o
 * certificado, nao e persistido pela API.
 */
final readonly class CredenciaisDaPrefeitura
{
    public function __construct(
        private string $usuario = '',
        #[SensitiveParameter] private string $senha = '',
        #[SensitiveParameter] private string $token = '',
    ) {}

    public function estaVazia(): bool
    {
        return $this->usuario === '' && $this->senha === '' && $this->token === '';
    }

    /**
     * @return array<string, string>
     */
    public function paraApi(): array
    {
        return array_filter([
            'usuario' => $this->usuario,
            'senha' => $this->senha,
            'token' => $this->token,
        ], static fn (string $valor): bool => $valor !== '');
    }
}
