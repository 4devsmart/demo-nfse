<?php

declare(strict_types=1);

namespace App\Filament\Resources\CodigosDeTributacaoNacional\Pages;

use App\Actions\Servicos\AtualizarCodigosPeloPortalNacional;
use App\Consultas\BuscaDeCodigosDeServico;
use App\Filament\Resources\CodigosDeTributacaoNacional\CodigoDeTributacaoNacionalResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Throwable;

class ListCodigosDeTributacaoNacional extends ListRecords
{
    protected static string $resource = CodigoDeTributacaoNacionalResource::class;

    public function getSubheading(): string
    {
        $servicos = app(BuscaDeCodigosDeServico::class);

        return __(
            ':codigos códigos sobre :itens subitens da lista anexa à LC 116/2003. É o cTribNac que a DPS declara em serv.cServ.',
            ['codigos' => $servicos->totalDeCodigos(), 'itens' => $servicos->totalDeItens()],
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('atualizarPeloPortalNacional')
                ->label(__('Atualizar pelo Portal Nacional'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('Lê a tabela publicada pelo Portal Nacional da NFS-e e atualiza os códigos. A lista da LC 116 não muda por aqui: ela sai do arquivo do projeto. Requer internet.'))
                ->action(fn (AtualizarCodigosPeloPortalNacional $atualizar) => $this->atualizar($atualizar)),
        ];
    }

    private function atualizar(AtualizarCodigosPeloPortalNacional $atualizar): void
    {
        try {
            $total = $atualizar->executar();
        } catch (Throwable $falha) {
            Notification::make()->danger()->title(__('Não deu para atualizar'))->body($falha->getMessage())->send();

            return;
        }

        Notification::make()->success()->title(__(':total códigos sincronizados', ['total' => $total]))->send();
    }
}
