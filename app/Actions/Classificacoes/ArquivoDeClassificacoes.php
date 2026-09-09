<?php

declare(strict_types=1);

namespace App\Actions\Classificacoes;

use RuntimeException;

/**
 * A copia da tabela `cClassTrib` que vai junto com o projeto, ja reduzida aos
 * codigos validos para NFS-e. Existe para que a demonstracao funcione offline,
 * como o arquivo de municipios.
 */
final readonly class ArquivoDeClassificacoes implements FonteDeClassificacoes
{
    public function __construct(private string $caminho) {}

    public function classificacoes(): array
    {
        if (! is_readable($this->caminho)) {
            throw new RuntimeException(__('Arquivo de classificações não encontrado em :caminho.', ['caminho' => $this->caminho]));
        }

        $conteudo = json_decode((string) file_get_contents($this->caminho), true);

        if (! is_array($conteudo)) {
            throw new RuntimeException(__('Arquivo de classificações inválido.'));
        }

        return array_values($conteudo);
    }
}
