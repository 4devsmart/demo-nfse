<?php

declare(strict_types=1);

namespace Tests\Apoio;

use App\Actions\Cidades\FonteDeCidades;

/**
 * A lista de municipios sem arquivo nem rede: e a fonte que `ImportarCidades`
 * espera, com o conteudo que o teste quiser.
 */
final readonly class FonteDeCidadesFalsa implements FonteDeCidades
{
    /**
     * @param  list<array{codigo_ibge: string, nome: string, uf: string}>  $municipios
     */
    public function __construct(private array $municipios) {}

    /**
     * @return list<array{codigo_ibge: string, nome: string, uf: string}>
     */
    public function municipios(): array
    {
        return $this->municipios;
    }
}
