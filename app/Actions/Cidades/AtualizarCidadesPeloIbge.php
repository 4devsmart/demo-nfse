<?php

declare(strict_types=1);

namespace App\Actions\Cidades;

/**
 * Recarrega a tabela a partir do servico oficial do IBGE. Existe separada da
 * carga local para que a tela nao precise escolher a fonte.
 */
final readonly class AtualizarCidadesPeloIbge
{
    public function __construct(private ApiDoIbge $ibge) {}

    public function executar(): int
    {
        return (new ImportarCidades($this->ibge))->executar();
    }
}
