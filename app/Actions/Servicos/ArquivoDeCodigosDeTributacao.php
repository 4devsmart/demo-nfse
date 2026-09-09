<?php

declare(strict_types=1);

namespace App\Actions\Servicos;

use RuntimeException;

/**
 * A copia da tabela `cTribNac` que vai junto com o projeto. Existe para que a
 * demonstracao funcione offline, como o arquivo de municipios;
 * `AtualizarCodigosPeloPortalNacional` recarrega da origem quando ha rede.
 */
final readonly class ArquivoDeCodigosDeTributacao implements FonteDeCodigosDeTributacao
{
    public function __construct(private string $caminho) {}

    public function codigos(): array
    {
        if (! is_readable($this->caminho)) {
            throw new RuntimeException(__('Arquivo de códigos de tributação não encontrado em :caminho.', ['caminho' => $this->caminho]));
        }

        $conteudo = json_decode((string) file_get_contents($this->caminho), true);

        if (! is_array($conteudo)) {
            throw new RuntimeException(__('Arquivo de códigos de tributação inválido.'));
        }

        return array_values($conteudo);
    }
}
