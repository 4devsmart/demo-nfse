<?php

declare(strict_types=1);

namespace App\Actions\Indicadores;

interface FonteDeIndicadores
{
    /**
     * @return list<array{codigo: string, tipo_operacao: string, caracteristica: string, local_do_fornecimento: string, dispositivo_legal: string}>
     */
    public function indicadores(): array;
}
