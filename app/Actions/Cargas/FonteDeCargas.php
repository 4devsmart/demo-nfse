<?php

declare(strict_types=1);

namespace App\Actions\Cargas;

interface FonteDeCargas
{
    /**
     * @return list<array{codigo: string, uf: string, descricao: string, percentual_federal: float, percentual_federal_importado: float, percentual_estadual: float, percentual_municipal: float, vigencia_inicio: string|null, vigencia_fim: string|null, versao: string}>
     */
    public function cargas(): array;
}
