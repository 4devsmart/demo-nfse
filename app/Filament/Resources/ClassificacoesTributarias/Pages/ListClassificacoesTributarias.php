<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClassificacoesTributarias\Pages;

use App\Actions\Classificacoes\AtualizarClassificacoesPelaSvrs;
use App\Consultas\BuscaDeClassificacoes;
use App\Filament\Resources\ClassificacoesTributarias\ClassificacaoTributariaResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Throwable;

class ListClassificacoesTributarias extends ListRecords
{
    protected static string $resource = ClassificacaoTributariaResource::class;

    public function getSubheading(): string
    {
        $total = app(BuscaDeClassificacoes::class)->total();

        return __(':total códigos carregados. É o par CST + cClassTrib que a DPS declara no grupo IBS/CBS.', ['total' => $total]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('atualizarPelaSvrs')
                ->label(__('Atualizar pela SVRS'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('Lê a tabela oficial no portal da SVRS, o mesmo que o Portal Nacional da NF-e indica, e atualiza os códigos válidos para NFS-e. Requer internet.'))
                ->action(fn (AtualizarClassificacoesPelaSvrs $atualizar) => $this->atualizar($atualizar)),
        ];
    }

    private function atualizar(AtualizarClassificacoesPelaSvrs $atualizar): void
    {
        try {
            $total = $atualizar->executar();
        } catch (Throwable $falha) {
            Notification::make()->danger()->title(__('Não deu para atualizar'))->body($falha->getMessage())->send();

            return;
        }

        Notification::make()->success()->title(__(':total classificações sincronizadas', ['total' => $total]))->send();
    }
}
