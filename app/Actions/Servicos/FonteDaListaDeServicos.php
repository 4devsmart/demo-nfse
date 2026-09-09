<?php

declare(strict_types=1);

namespace App\Actions\Servicos;

interface FonteDaListaDeServicos
{
    /**
     * @return list<array{codigo: string, descricao: string}>
     */
    public function itens(): array;
}
