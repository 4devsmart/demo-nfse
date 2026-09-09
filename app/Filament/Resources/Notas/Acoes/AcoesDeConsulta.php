<?php

declare(strict_types=1);

namespace App\Filament\Resources\Notas\Acoes;

use App\Actions\Municipios\PreverProvedorDoMunicipio;
use App\Actions\Notas\ConsultarDpsPendente;
use App\Actions\Notas\ConsultarNotaNoProvedor;
use App\Actions\Notas\ConsultarPorRps;
use App\Filament\Suporte\OperacaoFiscal;
use App\Fiscal\Pedidos\ConsultaPorRps;
use App\Models\Nota;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

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
            ->action(fn (Nota $nota, array $data, ConsultarPorRps $consultar) => OperacaoFiscal::executar(
                fn () => OperacaoFiscal::avisarRetornoCru(
                    $consultar->executar($nota, ConsultaPorRps::sobreORps(
                        numero: (string) $data['numero'],
                        serie: (string) $data['serie'],
                        tipo: (string) ($data['tipo'] ?? '1'),
                        codigoDeVerificacao: (string) ($data['codigo_verificacao'] ?? ''),
                    )),
                ),
            ));

        return OperacaoFiscal::bloqueadaQuando($acao, fn ($impedimentos): ?string => $impedimentos->paraFalarComOProvedor());
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
