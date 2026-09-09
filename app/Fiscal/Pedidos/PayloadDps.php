<?php

declare(strict_types=1);

namespace App\Fiscal\Pedidos;

/**
 * O corpo pronto de POST /v1/nfse/xml. Nasce so do ConstrutorDps: quem chama a
 * API nao monta array a mao.
 */
final readonly class PayloadDps
{
    /**
     * @param  array<string, mixed>  $conteudo
     */
    public function __construct(private array $conteudo) {}

    /**
     * @return array<string, mixed>
     */
    public function paraApi(): array
    {
        return $this->conteudo;
    }

    public function emJson(): string
    {
        return json_encode($this->conteudo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
