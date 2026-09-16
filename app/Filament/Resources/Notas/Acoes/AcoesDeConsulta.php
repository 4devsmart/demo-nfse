<?php

declare(strict_types=1);

namespace App\Filament\Resources\Notas\Acoes;

use App\Actions\Municipios\PreverProvedorDoMunicipio;
use App\Actions\Notas\ConsultarDpsPendente;
use App\Actions\Notas\ConsultarLoteDaNota;
use App\Actions\Notas\ConsultarNotaNoProvedor;
use App\Actions\Notas\ConsultarPorRps;
use App\Consultas\ConferenciaComOProvedor;
use App\Filament\Suporte\OperacaoFiscal;
use App\Fiscal\Pedidos\ConsultaPorRps;
use App\Fiscal\Respostas\NfseConsultada;
use App\Models\Nota;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

/**
 * Os três caminhos para perguntar ao provedor o que aconteceu com um documento.
 * São três porque a resposta depende de quanto se sabe:
 *
 *   - só o `id_dps`      → a DPS virou nota?          (recuperação após 502)
 *   - a chave de acesso  → como está esta nota?
 *   - série e número     → aquele RPS virou nota?
 */
final class AcoesDeConsulta
{
    /**
     * O fecho do modelo sem estado. Depois de um desfecho indeterminado é ISTO
     * que se chama: reenviar a DPS duplicaria documento fiscal.
     */
    public static function consultarDps(): Action
    {
        $acao = Action::make('consultarDps')
            ->label(__('Consultar DPS'))
            ->icon(Heroicon::OutlinedQuestionMarkCircle)
            ->color('warning')
            ->modalDescription(__('Depois de um desfecho indeterminado é isto que se chama: reenviar duplicaria o documento.'))
            ->visible(fn (Nota $nota): bool => $nota->status->pedeConsulta())
            ->action(fn (Nota $nota, ConsultarDpsPendente $consultar) => OperacaoFiscal::executar(
                fn () => OperacaoFiscal::avisarRetornoCru($consultar->executar($nota)),
            ));

        return OperacaoFiscal::bloqueadaQuando($acao, fn ($impedimentos): ?string => $impedimentos->paraConsultarADps());
    }

    /**
     * A segunda metade da transmissão nos provedores assíncronos. Conclui a
     * nota pelo protocolo; reenviar a DPS com o lote no provedor duplicaria o
     * RPS.
     */
    public static function consultarLote(): Action
    {
        $acao = Action::make('consultarLote')
            ->label(__('Consultar lote'))
            ->icon(Heroicon::OutlinedClock)
            ->color('info')
            ->visible(fn (Nota $nota): bool => $nota->status->aguardaLote())
            ->action(fn (Nota $nota, ConsultarLoteDaNota $consultar) => OperacaoFiscal::executar(
                fn () => $consultar->executar($nota),
                fn () => OperacaoFiscal::avisarDesfechoDaNota($nota->refresh()),
            ));

        return OperacaoFiscal::bloqueadaQuando($acao, fn ($impedimentos): ?string => $impedimentos->paraConsultarOLote());
    }

    /**
     * O estado da nota segundo o provedor, não segundo este banco. Serve para
     * conferir um cancelamento sem resposta, a chave já se tem.
     */
    public static function consultarNoProvedor(): Action
    {
        $acao = Action::make('consultarNoProvedor')
            ->label(__('Consultar no provedor'))
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->color('gray')
            ->visible(fn (Nota $nota): bool => filled($nota->chave))
            ->action(fn (Nota $nota, ConsultarNotaNoProvedor $consultar) => OperacaoFiscal::executar(
                fn () => OperacaoFiscal::avisarRetornoCru($consultar->executar($nota)),
            ));

        return OperacaoFiscal::bloqueadaQuando($acao, fn ($impedimentos): ?string => $impedimentos->paraConsultarPelaChave());
    }

    /**
     * O terceiro caminho: perguntar pelo par série/número, que este sistema
     * controla desde o rascunho, sem depender do `id_dps` nem da chave.
     */
    public static function consultarPorRps(): Action
    {
        $acao = Action::make('consultarPorRps')
            ->label(__('Consultar por RPS'))
            ->icon(Heroicon::OutlinedHashtag)
            ->color('gray')
            ->modalHeading(__('Aquele RPS virou nota?'))
            ->modalDescription(fn (Nota $nota): string => self::avisoDoRps($nota))
            ->modalSubmitActionLabel(__('Consultar'))
            // Tambem quando so ha serie e numero: e o caso da substituta que
            // ficou sem resposta, que nao tem id_dps nem chave para consultar.
            ->visible(fn (Nota $nota): bool => $nota->temDpsMontada() || $nota->temXmlAutorizado() || $nota->status->pedeConsulta())
            ->schema(self::camposDoRps())
            ->action(fn (Nota $nota, array $data, ConsultarPorRps $consultar, Page $livewire) => OperacaoFiscal::executar(
                function () use ($nota, $data, $consultar, $livewire): void {
                    $consulta = ConsultaPorRps::sobreORps(
                        numero: (string) $data['numero'],
                        serie: (string) $data['serie'],
                        tipo: (string) ($data['tipo'] ?? '1'),
                        codigoDeVerificacao: (string) ($data['codigo_verificacao'] ?? ''),
                    );

                    $livewire->replaceMountedAction(
                        'resultadoDaConsultaPorRps',
                        self::resultadoParaATela($nota, $consulta, NfseConsultada::doRetorno($consultar->executar($nota, $consulta))),
                    );
                },
            ));

        return OperacaoFiscal::bloqueadaQuando($acao, fn ($impedimentos): ?string => $impedimentos->paraFalarComOProvedor());
    }

    /**
     * O que a consulta por RPS achou, num modal próprio. Não tem botão: quem a
     * abre é `consultarPorRps`, trocando o modal do pedido pelo da resposta, e
     * as páginas a registram por `resultadoDaConsultaPorRpsAction()`.
     *
     * Antes o retorno ia cru num aviso de 400 caracteres, e o cancelamento, que
     * fica no fim do XML, nunca aparecia.
     */
    public static function resultadoDaConsultaPorRps(): Action
    {
        return Action::make('resultadoDaConsultaPorRps')
            ->modalHeading(fn (array $arguments): string => __('Consulta do RPS :rps', ['rps' => $arguments['rps'] ?? '']))
            ->modalWidth(Width::TwoExtraLarge)
            ->modalContent(fn (array $arguments): View => view('filament.notas.consulta-rps', ['resultado' => $arguments]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Fechar'));
    }

    /**
     * Só texto e booleano: os argumentos de uma ação vivem no estado do
     * Livewire, e é daqui que a view desenha. O documento XML não vai junto;
     * quem o guarda é "Buscar XML do evento", que consulta de novo do servidor.
     *
     * @return array<string, mixed>
     */
    private static function resultadoParaATela(Nota $nota, ConsultaPorRps $consulta, NfseConsultada $consultada): array
    {
        $conferencia = app(ConferenciaComOProvedor::class);
        $ehDaNota = $conferencia->ehORpsDaNota($nota, $consulta->numero, $consulta->serie);

        $estado = match (true) {
            ! $consultada->foiEncontrada() => 'sem-nota',
            $consultada->cancelada => 'cancelada',
            $consultada->substituidaPor !== '' => 'substituida',
            default => 'autorizada',
        };

        return [
            'rps' => "{$consulta->numero}/{$consulta->serie}",
            'estado' => $estado,
            'titulo' => match ($estado) {
                'sem-nota' => __('Este RPS não virou NFS-e'),
                'cancelada' => __('NFS-e :numero cancelada', ['numero' => $consultada->numero]),
                'substituida' => __('NFS-e :numero substituída', ['numero' => $consultada->numero]),
                default => __('NFS-e :numero autorizada', ['numero' => $consultada->numero]),
            },
            'apoio' => match ($estado) {
                'sem-nota' => __('O provedor não devolveu nota para este RPS. Se o lote ainda está em processamento, consulte de novo em instantes.'),
                'cancelada' => $consultada->canceladaEm === ''
                    ? __('O provedor devolveu o registro do cancelamento, sem data.')
                    : __('Cancelamento registrado em :data.', ['data' => $consultada->canceladaEm]),
                'substituida' => __('Substituída pela NFS-e :outra.', ['outra' => $consultada->substituidaPor]),
                default => __('Sem registro de cancelamento nem de substituição no retorno.'),
            },
            'campos' => array_filter([
                __('Número da NFS-e') => $consultada->numero,
                __('Código de verificação') => $consultada->codigoDeVerificacao,
                __('Emitida em') => $consultada->emitidaEm,
                __('Cancelada em') => $consultada->canceladaEm,
                __('Substituída por') => $consultada->substituidaPor,
            ], static fn (string $valor): bool => $valor !== ''),
            'mensagens' => array_map(
                static fn (array $mensagem): string => trim("{$mensagem['codigo']} {$mensagem['descricao']}"),
                $consultada->mensagens->paraArray(),
            ),
            'eh_da_nota' => $ehDaNota,
            'situacao_aqui' => $nota->status->getLabel(),
            'divergencias' => $conferencia->divergencias($nota, $consultada, $consulta->numero, $consulta->serie),
            'pode_guardar_evento' => $ehDaNota && $consultada->temDocumentoDeEvento()
                && $nota->status->teveEvento() && ! $nota->temXmlDoEvento(),
        ];
    }

    /**
     * @return array<int, TextInput>
     */
    private static function camposDoRps(): array
    {
        return [
            TextInput::make('numero')
                ->label(__('Número do RPS'))
                ->required()
                ->default(fn (Nota $nota): string => (string) $nota->numero),

            TextInput::make('serie')
                ->label(__('Série'))
                ->required()
                ->default(fn (Nota $nota): string => $nota->serie),

            TextInput::make('tipo')
                ->label(__('Tipo do RPS'))
                ->default('1')
                ->helperText(__('1 é o RPS comum.')),

            // O campo nao dava pista nenhuma do que era, e o codigo longo que
            // se tem a mao numa nota do Padrao Nacional e a chave de acesso.
            // Colar a chave aqui e o engano natural, e o retorno nao explica.
            TextInput::make('codigo_verificacao')
                ->label(__('Código de verificação'))
                ->placeholder(__('deixe vazio no Padrão Nacional'))
                ->helperText(__('Código curto impresso na nota, dos provedores ABRASF. Não é a chave de acesso, e o Padrão Nacional não usa: ali a nota é identificada pela chave.')),
        ];
    }

    /**
     * O que a consulta por RPS responde, e quando ela nao responde nada.
     *
     * Ela e o par serie/numero do mundo ABRASF. No Padrao Nacional o caminho
     * equivalente e a chave da DPS, e o pedido volta com "Chave da DPS não
     * informada" (X126) sem sequer montar envelope. O sistema sabe o leiaute do
     * municipio do emitente, entao pode dizer isso antes do clique em vez de
     * deixar a rejeicao explicar.
     */
    private static function avisoDoRps(Nota $nota): string
    {
        $comum = __('Pergunta ao provedor pelo par série/número. Vem preenchido com o desta nota, mas dá para consultar qualquer outro.');

        $previsto = app(PreverProvedorDoMunicipio::class)->executar($nota->empresa->municipio());

        if (! $previsto->ehPadraoNacional()) {
            return $comum;
        }

        return $comum."\n\n".__('O município do emitente é do Padrão Nacional, que não responde por RPS: a consulta volta com "Chave da DPS não informada". Use "Consultar DPS", que pergunta pelo id_dps, ou "Consultar no provedor", que pergunta pela chave.');
    }
}
