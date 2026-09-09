<?php

declare(strict_types=1);

namespace App\Filament\Resources\Notas\Acoes;

use App\Actions\Notas\EmitirNota;
use App\Actions\Notas\GerarDps;
use App\Actions\Notas\TransmitirNota;
use App\Filament\Resources\Notas\NotaResource;
use App\Filament\Suporte\OperacaoFiscal;
use App\Models\Nota;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Os dois passos da emissão, e o atalho que faz os dois.
 *
 * A API separa montar de transmitir: a primeira chamada devolve o `id_dps` sem
 * enviar nada ao provedor. Se a transmissão der timeout, é por esse
 * identificador que se consulta o que aconteceu.
 */
final class AcoesDeEmissao
{
    /**
     * Atalho: gera e transmite em sequência. Só aparece enquanto não há DPS
     * montada, depois disso o passo que falta é transmitir, e oferecer os dois
     * seria pedir para escolher entre a mesma coisa.
     */
    public static function emitir(): Action
    {
        $acao = Action::make('emitir')
            ->label(__('Emitir'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(__('Emitir a NFS-e'))
            ->modalDescription(__('Monta a DPS e transmite ao provedor do município, em sequência. Documento fiscal não se desfaz.'))
            ->modalSubmitActionLabel(__('Emitir'))
            ->visible(fn (Nota $nota): bool => $nota->status->permiteTransmitir() && ! $nota->temDpsMontada())
            ->action(fn (Nota $nota, EmitirNota $emitir) => OperacaoFiscal::executar(
                fn () => $emitir->executar($nota),
                fn () => OperacaoFiscal::avisarDesfechoDaNota($nota->refresh()),
            ))
            ->successRedirectUrl(fn (Nota $nota): string => NotaResource::getUrl('view', ['record' => $nota]));

        return OperacaoFiscal::bloqueadaQuando($acao, fn ($impedimentos): ?string => $impedimentos->paraEmitir());
    }

    /**
     * Passo 1 sozinho: monta o XML e devolve o `id_dps` sem falar com a
     * prefeitura. Não pede certificado, é o caminho de quem quer conferir o
     * documento antes de qualquer byte sair.
     */
    public static function gerarDps(): Action
    {
        return Action::make('gerarDps')
            ->label(__('Só gerar a DPS'))
            ->tooltip(__('Monta o XML e devolve o id_dps. Nada é enviado à prefeitura, e não precisa de certificado.'))
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('gray')
            ->visible(fn (Nota $nota): bool => $nota->status->permiteTransmitir() && ! $nota->temDpsMontada())
            ->action(fn (Nota $nota, GerarDps $gerar) => OperacaoFiscal::executar(
                fn () => $gerar->executar($nota),
                fn () => Notification::make()
                    ->success()
                    ->title(__('DPS montada'))
                    ->body(__('id_dps :id — provedor :provedor. Nada foi transmitido ainda.', ['id' => $nota->refresh()->id_dps, 'provedor' => $nota->provedor]))
                    ->send(),
            ));
    }

    /**
     * Passo 2: assina a DPS já montada e envia. Só aparece quando existe XML
     * para enviar.
     */
    public static function transmitir(): Action
    {
        $acao = Action::make('transmitir')
            ->label(__('Transmitir'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(__('Transmitir a DPS'))
            ->modalDescription(__('Assina a DPS já montada e envia ao provedor do município. Documento fiscal não se desfaz.'))
            ->modalSubmitActionLabel(__('Transmitir'))
            ->visible(fn (Nota $nota): bool => $nota->status->permiteTransmitir() && $nota->temDpsMontada())
            ->action(fn (Nota $nota, TransmitirNota $transmitir) => OperacaoFiscal::executar(
                fn () => $transmitir->executar($nota),
                fn () => OperacaoFiscal::avisarDesfechoDaNota($nota->refresh()),
            ))
            ->successRedirectUrl(fn (Nota $nota): string => NotaResource::getUrl('view', ['record' => $nota]));

        return OperacaoFiscal::bloqueadaQuando($acao, fn ($impedimentos): ?string => $impedimentos->paraTransmitir());
    }
}
