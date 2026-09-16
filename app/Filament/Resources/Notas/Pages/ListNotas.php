<?php

declare(strict_types=1);

namespace App\Filament\Resources\Notas\Pages;

use App\Consultas\ResumoDeNotas;
use App\Domain\Enums\StatusNota;
use App\Filament\Resources\Notas\Acoes\AcoesDeConsulta;
use App\Filament\Resources\Notas\NotaResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListNotas extends ListRecords
{
    protected static string $resource = NotaResource::class;

    /**
     * O modal com a resposta da consulta por RPS. Não aparece como botão: a
     * própria consulta o abre no lugar do seu modal, e para isso ele precisa
     * existir na página.
     */
    public function resultadoDaConsultaPorRpsAction(): Action
    {
        return AcoesDeConsulta::resultadoDaConsultaPorRps();
    }

    public function getSubheading(): string
    {
        return __('A API fiscal não guarda nada: o XML, o protocolo e a numeração ficam aqui.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('Nova NFS-e'))
                ->icon(Heroicon::Plus),
        ];
    }

    /**
     * Cada aba traz a contagem: dá para ver o que está pendente sem abrir.
     */
    public function getTabs(): array
    {
        $resumo = app(ResumoDeNotas::class);

        $abas = ['todas' => Tab::make(__('Todas'))->badge($resumo->total())];

        foreach (StatusNota::cases() as $status) {
            $quantidade = $resumo->quantidadePor($status);

            $abas[$status->value] = Tab::make($status->getLabel())
                ->icon($status->getIcon())
                ->badge($quantidade > 0 ? $quantidade : null)
                ->badgeColor($status->getColor())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', $status));
        }

        return $abas;
    }
}
