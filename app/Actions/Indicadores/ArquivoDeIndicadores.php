<?php

declare(strict_types=1);

namespace App\Actions\Indicadores;

use RuntimeException;

/**
 * A copia do Anexo VII que vai junto com o projeto, ja convertida de planilha
 * para JSON.
 */
final readonly class ArquivoDeIndicadores implements FonteDeIndicadores
{
    public function __construct(private string $caminho) {}

    public function indicadores(): array
    {
        if (! is_readable($this->caminho)) {
            throw new RuntimeException(__('Arquivo de indicadores não encontrado em :caminho.', ['caminho' => $this->caminho]));
        }

        $conteudo = json_decode((string) file_get_contents($this->caminho), true);

        if (! is_array($conteudo)) {
            throw new RuntimeException(__('Arquivo de indicadores inválido.'));
        }

        return array_values($conteudo);
    }
}
