<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cidades\Pages;

use App\Actions\Cidades\AtualizarCidadesPeloIbge;
use App\Consultas\BuscaDeCidades;
use App\Filament\Resources\Cidades\CidadeResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Throwable;

class ListCidades extends ListRecords
{
    protected static string $resource = CidadeResource::class;

    public function getSubheading(): string
    {
        $total = app(BuscaDeCidades::class)->total();

        return __(':total municípios carregados. O código IBGE é o que decide o provedor de NFS-e.', ['total' => $total]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('atualizarPeloIbge')
                ->label(__('Atualizar pelo IBGE'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('Busca a lista oficial de municípios no serviço do IBGE e atualiza a tabela. Requer internet.'))
                ->action(fn (AtualizarCidadesPeloIbge $atualizar) => $this->atualizar($atualizar)),

            CreateAction::make()->label(__('Novo município'))->slideOver(),
        ];
    }

    private function atualizar(AtualizarCidadesPeloIbge $atualizar): void
    {
        try {
            $total = $atualizar->executar();
        } catch (Throwable $falha) {
            Notification::make()->danger()->title(__('Não deu para atualizar'))->body($falha->getMessage())->send();

            return;
        }

        Notification::make()->success()->title(__(':total municípios sincronizados', ['total' => $total]))->send();
    }
}
