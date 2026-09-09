<?php

declare(strict_types=1);

namespace App\Actions\Cidades;

interface FonteDeCidades
{
    /**
     * @return list<array{codigo_ibge: string, nome: string, uf: string}>
     */
    public function municipios(): array;
}
