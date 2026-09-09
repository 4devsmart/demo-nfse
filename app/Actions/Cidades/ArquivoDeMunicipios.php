<?php

declare(strict_types=1);

namespace App\Actions\Cidades;

use RuntimeException;

/**
 * A copia dos 5.571 municipios que vai junto com o projeto. Existe para que a
 * demonstracao funcione offline.
 */
final readonly class ArquivoDeMunicipios implements FonteDeCidades
{
    public function __construct(private string $caminho) {}

    public function municipios(): array
    {
        if (! is_readable($this->caminho)) {
            throw new RuntimeException(__('Arquivo de municípios não encontrado em :caminho.', ['caminho' => $this->caminho]));
        }

        $conteudo = json_decode((string) file_get_contents($this->caminho), true);

        if (! is_array($conteudo)) {
            throw new RuntimeException(__('Arquivo de municípios inválido.'));
        }

        return array_values($conteudo);
    }
}
