<?php

declare(strict_types=1);

namespace App\Actions\Servicos;

/**
 * Recarrega a tabela `cTribNac` a partir do Portal Nacional da NFS-e. Existe
 * separada da carga local para que a tela nao precise escolher a fonte.
 */
final readonly class AtualizarCodigosPeloPortalNacional
{
    public function __construct(
        private PortalDaNfseNacional $portal,
        private ArquivoDeCodigosDeTributacao $arquivo,
    ) {}

    public function executar(): int
    {
        return (new ImportarCodigosDeTributacaoNacional($this->portal, $this->arquivo))->executar();
    }
}
