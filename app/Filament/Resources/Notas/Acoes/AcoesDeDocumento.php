<?php

declare(strict_types=1);

namespace App\Filament\Resources\Notas\Acoes;

use App\Actions\Notas\BuscarXmlDoEvento;
use App\Actions\Notas\PreverXmlDaDps;
use App\Filament\Suporte\OperacaoFiscal;
use App\Fiscal\Traducao\MontadorDaDps;
use App\Models\Nota;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Os arquivos da nota: o impresso, os XML e o JSON que sai daqui.
 *
 * Vale distinguir os três documentos, porque é fácil confundi-los:
 *
 *   - o JSON  → o que este sistema monta e envia para a API;
 *   - o XML da DPS → o que a API montou a partir desse JSON;
 *   - o XML da NFS-e → o que a prefeitura devolveu autorizado. Sem segunda via;
 *   - o XML do evento → o cancelamento ou a substituição que veio depois.
 *
 * Os dois primeiros dá para ver antes de qualquer coisa sair, e é de propósito:
 * conferir o documento é o passo que falta entre preencher e transmitir.
 */
final class AcoesDeDocumento
{
    /**
     * O impresso oficial, desenhado pela API a partir do XML autorizado. Só
     * existe depois da autorização: antes dela não há número nem código de
     * verificação para imprimir.
     *
     * Numa nota cancelada ele continua dizendo "NFS-e Gerada" no campo SITUAÇÃO
     * DA NFS-E, e não é defeito do desenho: o XML autorizado foi assinado antes
     * do evento existir, e não há como ele saber do cancelamento. A API não tem
     * rota de impresso de evento para NFS-e, como tem para CT-e e MDF-e, e o
     * caminho `/v1/nfse/pdf` pela chave devolve `pdf_nao_gerado` neste
     * provedor. O que registra o evento é o XML dele, que vem da fila DF-e.
     *
     * O aviso segue a situação da nota, e não o documento já buscado: quem
     * ainda não trouxe o evento é justamente quem mais precisa saber que o
     * impresso não o mostra.
     */
    public static function baixarDanfse(): Action
    {
        $acao = Action::make('danfse')
            ->label(__('DANFSE'))
            ->icon(Heroicon::OutlinedDocumentCheck)
            ->color('primary')
            ->tooltip(fn (Nota $nota): ?string => $nota->status->teveEvento()
                ? (string) __('O impresso sai do XML autorizado, que é anterior ao evento: ele imprime "NFS-e Gerada". O que registra o evento é o XML dele.')
                : null)
            ->url(
                fn (Nota $nota): ?string => $nota->temXmlAutorizado() ? route('notas.danfse', $nota) : null,
                shouldOpenInNewTab: true,
            );

        return OperacaoFiscal::bloqueadaQuando($acao, fn ($impedimentos): ?string => $impedimentos->paraImprimir());
    }

    public static function baixarXml(): ActionGroup
    {
        return ActionGroup::make([
            self::arquivoXml('nfse', __('XML da NFS-e autorizada'), fn (Nota $nota): bool => $nota->temXmlAutorizado()),
            self::arquivoXml('evento', __('XML do evento'), fn (Nota $nota): bool => $nota->temXmlDoEvento()),
            self::arquivoXml('dps', __('XML da DPS enviada'), fn (Nota $nota): bool => $nota->temDpsMontada()),
        ])
            ->label(__('Baixar XML'))
            ->icon(Heroicon::OutlinedCodeBracketSquare)
            ->dropdown();
    }

    /**
     * Vai buscar no ADN o documento do evento que cancelou a nota.
     *
     * A resposta do cancelamento não o entrega: no Padrão Nacional ela volta
     * com o evento registrado, protocolo vazio e, no campo do XML, a frase
     * "Indice informado não encontrado". A consulta pela chave também não, ela
     * devolve a NFS-e como foi autorizada. O documento existe, assinado, na
     * fila DF-e do emitente, e é de lá que ele vem.
     */
    public static function buscarXmlDoEvento(): Action
    {
        $acao = Action::make('buscarXmlDoEvento')
            ->label(__('Buscar XML do evento'))
            ->icon(Heroicon::OutlinedInboxArrowDown)
            ->color('gray')
            ->modalHeading(__('Buscar o documento do cancelamento'))
            ->modalDescription(fn (Nota $nota): string => $nota->identificadaPeloNumero()
                ? __('Consulta esta nota no provedor pelo RPS e guarda o registro do cancelamento que vem com ela. Nada é enviado ao provedor: é só leitura.')
                : __('Percorre a fila DF-e do emitente até achar o evento desta nota. Nada é enviado ao provedor: é só leitura.'))
            ->modalSubmitActionLabel(__('Buscar'))
            ->visible(fn (Nota $nota): bool => $nota->status->teveEvento() && ! $nota->temXmlDoEvento())
            ->action(fn (Nota $nota, BuscarXmlDoEvento $buscar) => OperacaoFiscal::executar(
                fn () => self::avisarABusca($buscar->executar($nota), $nota),
            ));

        return OperacaoFiscal::bloqueadaQuando($acao, fn ($impedimentos): ?string => $impedimentos->paraBuscarOEvento());
    }

    /**
     * Mostra o corpo exato que iria para `POST /v1/nfse/xml`, montado a partir
     * do cadastro. Abrir esta janela não envia nada.
     */
    public static function verPayload(): Action
    {
        return Action::make('verPayload')
            ->label(__('Ver JSON da DPS'))
            ->icon(Heroicon::OutlinedCodeBracket)
            ->color('gray')
            ->modalHeading(__('O corpo que vai para POST /v1/nfse/xml'))
            ->modalDescription(__('Montado pelo ConstrutorDps a partir do cadastro. Nada é enviado ao abrir esta janela.'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Fechar'))
            ->modalContent(fn (Nota $nota, MontadorDaDps $montador) => view('filament.notas.payload', [
                'json' => self::jsonDaDps($nota, $montador),
            ]));
    }

    /**
     * O XML montado, antes de existir DPS gravada. Ao contrário do download,
     * que só serve depois de `GerarDps`, esta janela mostra o documento de um
     * rascunho: é onde se descobre que um grupo saiu vazio ou que um campo não
     * chegou ao XML.
     *
     * Abrir chama a API, e é só o que acontece: nada é assinado, nada é
     * transmitido e o rascunho não muda de status.
     */
    public static function verXml(): Action
    {
        return Action::make('verXml')
            ->label(__('Ver XML da DPS'))
            ->icon(Heroicon::OutlinedCodeBracketSquare)
            ->color('gray')
            ->modalHeading(__('O XML que a API monta a partir do JSON'))
            ->modalDescription(__('Nada é assinado nem transmitido, e o rascunho não muda de status.'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Fechar'))
            ->modalContent(fn (Nota $nota, PreverXmlDaDps $prever) => view('filament.notas.xml', [
                'xml' => self::xmlDaDps($nota, $prever),
            ]));
    }

    /**
     * Não achar não é erro: o ADN publica o documento depois de registrar o
     * evento, e a fila DF-e é do Padrão Nacional, não de todo provedor. O aviso
     * não afirma que a fila acabou, só que o evento não estava nela.
     */
    private static function avisarABusca(bool $achou, Nota $nota): void
    {
        Notification::make()
            ->status($achou ? 'success' : 'warning')
            ->title(match (true) {
                $achou => __('Documento do evento guardado'),
                $nota->identificadaPeloNumero() => __('O provedor não devolveu registro de evento'),
                default => __('Nenhum evento desta nota na fila'),
            })
            ->body(match (true) {
                $achou => __('Ele está em "Baixar XML → XML do evento".'),
                $nota->identificadaPeloNumero() => __('A consulta pelo RPS desta nota não trouxe cancelamento nem substituição. Confira pela "Consultar por RPS", que mostra o que o provedor respondeu.'),
                default => __('A fila DF-e do emitente não trouxe evento para esta chave. O ADN publica o documento depois de registrar o evento: se ele é recente, vale tentar de novo em alguns minutos.'),
            })
            ->persistent()
            ->send();
    }

    /**
     * @param  Closure(Nota): bool  $quandoExiste
     */
    private static function arquivoXml(string $documento, string $rotulo, Closure $quandoExiste): Action
    {
        return Action::make("xmlDa{$documento}")
            ->label($rotulo)
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible($quandoExiste)
            ->url(fn (Nota $nota): string => route('notas.xml', ['nota' => $nota, 'documento' => $documento]));
    }

    /**
     * Um rascunho incompleto ainda não dá para montar, e isso não é erro.
     * Mostrar o motivo dentro da janela ensina mais que uma tela de exceção.
     */
    private static function jsonDaDps(Nota $nota, MontadorDaDps $montador): string
    {
        try {
            return $montador->montar($nota)->emJson();
        } catch (Throwable $falha) {
            return '// '.__('A DPS ainda não pode ser montada:')."\n// {$falha->getMessage()}";
        }
    }

    /**
     * Aqui há dois motivos para não haver XML, e a janela distingue os dois: o
     * rascunho incompleto, que `montar()` recusa antes de sair, e a API fora do
     * ar, que só se descobre chamando. Nenhum dos dois é erro de tela.
     */
    private static function xmlDaDps(Nota $nota, PreverXmlDaDps $prever): string
    {
        try {
            return $prever->executar($nota);
        } catch (Throwable $falha) {
            return '<!-- '.__('O XML ainda não pode ser montado:')."\n     {$falha->getMessage()} -->";
        }
    }
}
