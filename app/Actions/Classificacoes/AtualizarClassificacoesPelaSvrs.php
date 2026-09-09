<?php

declare(strict_types=1);

namespace App\Actions\Classificacoes;

/**
 * Recarrega a tabela a partir do portal oficial da SVRS. Existe separada da
 * carga local para que a tela nao precise escolher a fonte.
 */
final readonly class AtualizarClassificacoesPelaSvrs
{
    public function __construct(private PortalDaSvrs $portal) {}

    public function executar(): int
    {
        return (new ImportarClassificacoesTributarias($this->portal))->executar();
    }
}
