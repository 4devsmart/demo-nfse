<?php

declare(strict_types=1);

namespace App\Actions\Servicos;

interface FonteDeCodigosDeTributacao
{
    /**
     * @return list<array{codigo: string, item_lista_servico: string, descricao: string}>
     */
    public function codigos(): array;
}
