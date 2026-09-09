<?php

declare(strict_types=1);

namespace App\Filament\Resources\IndicadoresDeOperacao\Pages;

use App\Consultas\BuscaDeIndicadores;
use App\Filament\Resources\IndicadoresDeOperacao\IndicadorDeOperacaoResource;
use Filament\Resources\Pages\ListRecords;

class ListIndicadoresDeOperacao extends ListRecords
{
    protected static string $resource = IndicadorDeOperacaoResource::class;

    /**
     * Sem ação de atualizar, ao contrário das outras duas tabelas oficiais: o
     * Anexo VII é publicado em planilha, e não há página nem JSON de onde ler.
     */
    public function getSubheading(): string
    {
        $total = app(BuscaDeIndicadores::class)->total();

        return __(':total códigos do Anexo VII da NT 007, baseados no art. 11 da LC 214/2025. É o cIndOp que diz onde a operação ocorreu.', ['total' => $total]);
    }
}
