<?php

declare(strict_types=1);

namespace App\Actions\Servicos;

interface FonteDaNbs
{
    /**
     * @return list<array{item_lista_servico: string, nbs: string, descricao: string}>
     */
    public function correlacoes(): array;
}
