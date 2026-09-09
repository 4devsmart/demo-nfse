<?php

declare(strict_types=1);

namespace App\Actions\Classificacoes;

interface FonteDeClassificacoes
{
    /**
     * @return list<array{codigo: string, cst: string, nome_cst: string, descricao: string, percentual_reducao_ibs: float, percentual_reducao_cbs: float, exige_tributo: bool, permite_credito_presumido: bool, tributacao_regular: bool, vigencia_inicio: string|null, vigencia_fim: string|null, url_legislacao: string|null}>
     */
    public function classificacoes(): array;
}
