<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use App\Actions\Municipios\PreverProvedorDoMunicipio;
use App\Actions\Municipios\ProvedorPrevisto;
use App\Consultas\BuscaDeCargasTributarias;
use App\Consultas\BuscaDeCidades;
use App\Consultas\BuscaDeClassificacoes;
use App\Consultas\BuscaDeCodigosDeServico;
use App\Consultas\BuscaDeIndicadores;
use App\Consultas\ClientesTomadores;
use App\Consultas\EmpresasEmitentes;
use App\Consultas\EstadoDoFormulario;
use App\Consultas\PadroesDaEmpresa;
use App\Consultas\PreviaDosValores;
use App\Domain\Enums\RetencaoIssqn;
use App\Domain\Enums\SituacaoTributariaPisCofins;
use App\Domain\Enums\TipoSuspensaoDeExigibilidade;
use App\Domain\ValueObjects\Dinheiro;
use App\Fiscal\Dps\RetencoesFederais;
use App\Models\CargaTributariaAproximada;
use App\Models\Nota;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;

/**
 * A tributacao perguntada em vez de despejada, como faz o Emissor Nacional:
 * "ha retencao?" antes de "por quem", "esta suspensa?" antes do numero do
 * processo. Para o caso comum, que e "nao" em tudo, a tela fica curta.
 *
 * As perguntas nao sao colunas, mas VAO no `$data`: e `TributacaoRespondida`
 * quem as traduz nas colunas que elas governam, e quem as descarta depois.
 *
 * O caminho curto, desidratar o campo escondido, nao serve: o Filament passa a
 * valida-lo junto, e um `required()` invisivel recusa a gravacao com um erro que
 * nao aparece em lugar nenhum da tela. Mandar a resposta e deixar o dominio
 * decidir custa uma classe e nao mente para quem opera.
 */
final class PerguntasDeTributacao
{
    /**
     * @return array<int, mixed>
     */
    public static function campos(): array
    {
        return [
            ...self::retencao(),
            ...self::suspensao(),
            ...self::beneficio(),
            ...self::retencoesFederais(),
            ...self::reformaTributaria(),
            ...self::totaisAproximados(),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function retencao(): array
    {
        return [
            self::pergunta('tem_retencao', __('Há retenção do ISSQN pelo tomador?'))
                ->afterStateHydrated(fn (ToggleButtons $campo, ?Nota $record) => $campo->state(
                    $record?->retencao_issqn instanceof RetencaoIssqn
                        && $record->retencao_issqn !== RetencaoIssqn::NaoRetido
                ))
                ->afterStateUpdated(function (bool $state, Set $set): void {
                    $set('retencao_issqn', $state
                        ? RetencaoIssqn::RetidoPeloTomador->value
                        : RetencaoIssqn::NaoRetido->value);
                }),

            Select::make('retencao_issqn')
                ->label(__('Retido por quem?'))
                ->options([
                    RetencaoIssqn::RetidoPeloTomador->value => RetencaoIssqn::RetidoPeloTomador->getLabel(),
                    RetencaoIssqn::RetidoPeloIntermediario->value => RetencaoIssqn::RetidoPeloIntermediario->getLabel(),
                ])
                ->default(RetencaoIssqn::NaoRetido)
                ->native(false)
                ->required()
                ->columnSpan(8)
                ->visible(fn (Get $get): bool => (bool) $get('tem_retencao'))
                ->helperText(__('Quando retido, o ISSQN sai do líquido do prestador.')),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function suspensao(): array
    {
        return [
            self::pergunta('tem_suspensao', __('A exigibilidade do ISSQN está suspensa?'))
                ->afterStateHydrated(fn (ToggleButtons $campo, ?Nota $record) => $campo->state($record?->tipo_suspensao !== null))
                ->afterStateUpdated(function (bool $state, Set $set): void {
                    if ($state) {
                        return;
                    }

                    $set('tipo_suspensao', null);
                    $set('numero_processo_suspensao', null);
                }),

            Select::make('tipo_suspensao')
                ->label(__('Tipo de suspensão'))
                ->options(TipoSuspensaoDeExigibilidade::class)
                ->native(false)
                ->required()
                ->columnSpan(5)
                ->visible(fn (Get $get): bool => (bool) $get('tem_suspensao')),

            TextInput::make('numero_processo_suspensao')
                ->label(__('Número do processo'))
                ->placeholder('0001234-56.2026.8.19.0001')
                ->required()
                ->columnSpan(3)
                ->visible(fn (Get $get): bool => (bool) $get('tem_suspensao')),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function beneficio(): array
    {
        return [
            self::pergunta('tem_beneficio', __('O serviço está amparado por benefício municipal?'))
                ->afterStateHydrated(fn (ToggleButtons $campo, ?Nota $record) => $campo->state(filled($record?->numero_beneficio_municipal)))
                ->afterStateUpdated(function (bool $state, Set $set): void {
                    if ($state) {
                        return;
                    }

                    $set('numero_beneficio_municipal', null);
                    $set('percentual_reducao_base', null);
                }),

            TextInput::make('numero_beneficio_municipal')
                ->label(__('Número do benefício'))
                ->required()
                ->columnSpan(4)
                ->visible(fn (Get $get): bool => (bool) $get('tem_beneficio')),

            Campos::percentual('percentual_reducao_base', __('Redução da base de cálculo'))
                ->live(onBlur: true)
                ->columnSpan(4)
                ->visible(fn (Get $get): bool => (bool) $get('tem_beneficio'))
                ->helperText(__('Reduz a base, logo reduz o ISSQN devido.')),
        ];
    }

    /**
     * As aliquotas chegam preenchidas do cadastro do emitente. O que se responde
     * aqui e outra coisa: QUAIS das tres contribuicoes sociais o tomador retem.
     *
     * A distincao e o assunto inteiro da NT 007. `vPis` e `vCofins` sao valores
     * DEVIDOS na operacao; o que o tomador RETEM de PIS, COFINS e CSLL vai
     * somado num campo so, `vRetCSLL`, e quem diz a composicao dessa soma e o
     * `tpRetPisCofins`. Por isso a lista marcada, e nao um "sim/nao" unico.
     *
     * IRRF e previdenciaria nao entram na lista: no leiaute so existe o campo
     * do valor retido, entao aliquota informada ja e retencao.
     *
     * @return array<int, mixed>
     */
    private static function retencoesFederais(): array
    {
        return [
            self::pergunta('tem_retencao_federal', __('Há retenção de tributos federais?'))
                ->afterStateHydrated(fn (ToggleButtons $campo, ?Nota $record) => $campo->state($record?->retencoesFederais() !== null))
                ->afterStateUpdated(function (bool $state, Set $set): void {
                    if ($state) {
                        return;
                    }

                    $set('cst_pis_cofins', null);
                    $set('contribuicoes_retidas', []);

                    foreach (['pis', 'cofins', 'csll', 'irrf', 'previdenciaria'] as $tributo) {
                        $set("aliquota_{$tributo}", 0);
                    }
                })
                ->helperText(__('As alíquotas vêm do cadastro do emitente. Aqui se diz o que o tomador retém desta nota.')),

            Select::make('cst_pis_cofins')
                ->label(__('CST do PIS/COFINS'))
                ->options(SituacaoTributariaPisCofins::deSaida())
                ->native(false)
                ->required()
                ->live()
                ->columnSpan(12)
                ->visible(fn (Get $get): bool => (bool) $get('tem_retencao_federal'))
                ->helperText(__('Decide se há PIS e COFINS a calcular: situação isenta, imune ou suspensa não gera valor devido.'))
                ->default(fn (): mixed => self::padraoDoEmitente('cst_pis_cofins')),

            Campos::percentual('aliquota_pis', __('PIS'))
                ->live(onBlur: true)
                ->columnSpan(4)
                ->visible(fn (Get $get): bool => (bool) $get('tem_retencao_federal'))
                ->default(fn (): mixed => self::padraoDoEmitente('aliquota_pis')),

            Campos::percentual('aliquota_cofins', __('COFINS'))
                ->live(onBlur: true)
                ->columnSpan(4)
                ->visible(fn (Get $get): bool => (bool) $get('tem_retencao_federal'))
                ->default(fn (): mixed => self::padraoDoEmitente('aliquota_cofins')),

            Campos::percentual('aliquota_csll', __('CSLL'))
                ->live(onBlur: true)
                ->columnSpan(4)
                ->visible(fn (Get $get): bool => (bool) $get('tem_retencao_federal'))
                ->default(fn (): mixed => self::padraoDoEmitente('aliquota_csll')),

            Campos::percentual('aliquota_irrf', __('IRRF'))
                ->live(onBlur: true)
                ->columnSpan(4)
                ->visible(fn (Get $get): bool => (bool) $get('tem_retencao_federal'))
                ->helperText(__('Informado é retido.'))
                ->default(fn (): mixed => self::padraoDoEmitente('aliquota_irrf')),

            Campos::percentual('aliquota_previdenciaria', __('Previdenciária'))
                ->live(onBlur: true)
                ->columnSpan(4)
                ->visible(fn (Get $get): bool => (bool) $get('tem_retencao_federal'))
                ->helperText(__('Só na cessão de mão de obra. Informado é retido.'))
                ->default(fn (): mixed => self::padraoDoEmitente('aliquota_previdenciaria')),

            CheckboxList::make('contribuicoes_retidas')
                ->label(__('Contribuições sociais retidas pelo tomador'))
                ->options([
                    'pis' => __('PIS'),
                    'cofins' => __('COFINS'),
                    'csll' => __('CSLL'),
                ])
                ->columns(3)
                ->live()
                ->columnSpan(12)
                ->visible(fn (Get $get): bool => (bool) $get('tem_retencao_federal'))
                ->afterStateHydrated(fn (CheckboxList $campo, ?Nota $record) => $campo->state(array_keys(array_filter([
                    'pis' => (bool) $record?->retem_pis,
                    'cofins' => (bool) $record?->retem_cofins,
                    'csll' => (bool) $record?->retem_csll,
                ]))))
                ->helperText(fn (Get $get): string => self::avisoDoPisoDaCsrf($get)
                    ?? __('O que for marcado sai do líquido do prestador. O que não for continua devido por ele.')),
        ];
    }

    /**
     * O piso de dispensa da CSRF, quando ele alcanca esta nota.
     *
     * A Lei 10.833/2003, art. 31, parag. 3, dispensa a retencao das tres
     * contribuicoes em pagamento de ate cinco mil reais. Nao da para suprimir a
     * retencao sozinho: o parag. 4 manda somar os pagamentos do mes ao mesmo
     * prestador, e o acumulado do mes nao esta neste sistema. Entao a tela
     * aponta o piso e deixa a conta com quem tem os dados.
     */
    private static function avisoDoPisoDaCsrf(Get $get): ?string
    {
        if (EstadoDoFormulario::lista(['contribuicoes_retidas' => $get('contribuicoes_retidas')], 'contribuicoes_retidas') === []) {
            return null;
        }

        $servico = self::valorDoCampo($get, 'valor_servico');
        $piso = RetencoesFederais::CENTAVOS_DISPENSADOS_DA_CSRF / 100;

        if ($servico > $piso) {
            return null;
        }

        return __(
            'A Lei 10.833/2003 dispensa a retenção das três em pagamento de até :piso, e esta nota é de :valor. '
            .'Antes de manter as marcações, some o que este tomador já pagou a você no mês: é o §4º do mesmo artigo.',
            [
                'piso' => Dinheiro::deReais($piso)->formatado(),
                'valor' => Dinheiro::deReais($servico)->formatado(),
            ],
        );
    }

    /**
     * A classificacao de IBS/CBS. A DPS so classifica: nao ha valor a informar,
     * porque quem calcula os dois e a Sefin Nacional, na autorizacao.
     *
     * @return array<int, mixed>
     */
    private static function reformaTributaria(): array
    {
        $classificacoes = app(BuscaDeClassificacoes::class);
        $indicadores = app(BuscaDeIndicadores::class);

        return [
            // Já vem "sim" quando o emitente classificou no cadastro. Sem os
            // três padrões, viria "sim" para abrir três campos vazios e
            // obrigatórios em toda nota, o que é pior que a pergunta.
            self::pergunta('tem_ibs_cbs', __('Informar a classificação de IBS/CBS?'), padrao: self::emitenteClassificaIbsCbs(...))
                ->afterStateHydrated(fn (ToggleButtons $campo, ?Nota $record) => self::respostaDoRegistro(
                    $campo,
                    $record,
                    static fn (Nota $nota): bool => filled($nota->classificacao_tributaria),
                ))
                ->afterStateUpdated(function (bool $state, Set $set): void {
                    if ($state) {
                        return;
                    }

                    $set('cst_ibs_cbs', null);
                    $set('indicador_de_operacao', null);
                    $set('classificacao_tributaria', null);
                    $set('codigo_credito_presumido', null);
                    $set('nbs', null);
                })
                ->helperText(__('Ainda opcional no Padrão Nacional. Respondendo "sim", os três campos são obrigatórios: a classificação só vale inteira.')),

            Select::make('cst_ibs_cbs')
                ->label(__('CST do IBS/CBS'))
                ->options(fn (): array => $classificacoes->situacoesTributarias())
                ->native(false)
                ->required()
                ->live()
                ->columnSpan(5)
                ->visible(fn (Get $get): bool => (bool) $get('tem_ibs_cbs'))
                ->afterStateUpdated(fn (Set $set) => $set('classificacao_tributaria', null))
                ->default(fn (): mixed => self::padraoDoEmitente('cst_ibs_cbs')),

            Select::make('classificacao_tributaria')
                ->label(__('Classificação tributária'))
                ->placeholder(__('Escolha o CST primeiro'))
                ->options(fn (Get $get): array => $classificacoes->paraSelecao(self::texto($get('cst_ibs_cbs'))))
                ->native(false)
                ->searchable()
                ->required()
                // `live` porque o campo de crédito presumido aparece ou some
                // conforme a classificação escolhida.
                ->live()
                ->columnSpan(7)
                ->visible(fn (Get $get): bool => (bool) $get('tem_ibs_cbs'))
                ->helperText(__('Escolha o CST primeiro; ele filtra as classificações.'))
                ->default(fn (): mixed => self::padraoDoEmitente('classificacao_tributaria')),

            Select::make('indicador_de_operacao')
                ->label(__('Indicador da operação'))
                ->options(fn (): array => $indicadores->paraSelecao())
                ->native(false)
                ->searchable()
                ->required()
                ->live()
                ->columnSpan(8)
                ->visible(fn (Get $get): bool => (bool) $get('tem_ibs_cbs'))
                ->default(fn (): mixed => self::padraoDoEmitente('indicador_de_operacao'))
                ->helperText(fn (Get $get): string => $indicadores->explicacaoDe(self::texto($get('indicador_de_operacao')))
                    ?? __('Onde a operação se considera ocorrida.')),

            // Só para os provedores que leem o `cLocalidadeIncid` dentro do RPS.
            // No Padrão Nacional e nos outros ABRASF ele vem calculado na NFS-e
            // devolvida, e perguntar aqui seria pedir um dado que ninguém lê.
            //
            // Escondido, o campo continua gravando. Trocar para um emitente
            // cujo provedor não lê o campo limpa a localidade, e sem gravar o
            // vazio a do emitente anterior ficava no banco e ia na DPS.
            Select::make('cidade_incidencia_ibs_cbs_id')
                ->label(__('Localidade de incidência do IBS/CBS'))
                ->placeholder(__('Busque pelo nome ou pelo código IBGE'))
                ->searchable()
                ->native(false)
                ->required(fn (Get $get): bool => self::perguntaALocalidade($get))
                ->columnSpan(12)
                ->visible(fn (Get $get): bool => self::perguntaALocalidade($get))
                ->dehydratedWhenHidden()
                ->getSearchResultsUsing(fn (string $search): array => app(BuscaDeCidades::class)->procurar($search))
                ->getOptionLabelUsing(fn (mixed $value): ?string => app(BuscaDeCidades::class)->rotuloDe(self::chave($value)))
                ->afterStateHydrated(fn (Get $get, Set $set) => self::sugerirLocalidadeDoTomador($get, $set))
                ->helperText(__('O provedor deste emitente pede o local da operação (LC 214/2025, art. 11). Vem do município do tomador, que é a regra geral; troque se a operação for sobre imóvel, presencial ou em evento, porque aí vale onde ela acontece.')),

            // Aqui, e não na etapa do serviço, porque é aqui que a obrigação
            // nasce: a rejeição E0322 exige o `cNBS` quando a DPS declara
            // qualquer informação de IBS/CBS, e não quando ela descreve o
            // serviço. Fora deste grupo o campo não tem o que dizer.
            //
            // As opções vêm filtradas pelo subitem do código do serviço: são
            // 676 itens de NBS na tabela, e o serviço da nota costuma ter menos
            // de cinco.
            Select::make('nbs')
                ->label(__('Item da NBS'))
                ->placeholder(__('Escolha o código do serviço primeiro'))
                ->options(fn (Get $get): array => app(BuscaDeCodigosDeServico::class)->nbsParaSelecao($get('codigo_servico')))
                ->getOptionLabelUsing(fn (mixed $value): ?string => app(BuscaDeCodigosDeServico::class)->rotuloDaNbs($value))
                ->searchable()
                ->native(false)
                ->required()
                ->columnSpan(12)
                ->visible(fn (Get $get): bool => (bool) $get('tem_ibs_cbs'))
                ->default(fn (): mixed => self::padraoDoEmitente('nbs'))
                ->helperText(fn (Get $get): string => self::ajudaDaNbs($get)),

            // O campo só aparece quando a classificação escolhida permite, e
            // não sempre que o grupo está aberto: o texto de ajuda dizia isso e
            // o campo fazia o contrário, aparecendo nas 71 classificações
            // quando só uma delas aceita o código.
            TextInput::make('codigo_credito_presumido')
                ->label(__('Código do crédito presumido'))
                ->columnSpan(4)
                ->visible(fn (Get $get): bool => (bool) $get('tem_ibs_cbs')
                    && $classificacoes->permiteCreditoPresumido(self::texto($get('classificacao_tributaria'))))
                ->helperText(__('A classificação escolhida permite informá-lo.')),
        ];
    }

    /**
     * O estado de um seletor nao chega necessariamente como texto. Chave
     * numerica de array PHP vira inteiro, entao a opcao "410014" volta do
     * formulario como 410014, enquanto "000001" volta como texto por causa do
     * zero a esquerda.
     *
     * Recusar o inteiro era mudo e caro: 67 das 71 classificacoes e 14 dos 36
     * indicadores nao tem zero a esquerda. O campo do credito presumido nao
     * aparecia para classificacao nenhuma, nem para a unica que o permite, e a
     * explicacao do indicador caia sempre no texto generico.
     */
    /**
     * O estado de um seletor nao chega necessariamente como texto. Chave
     * numerica de array PHP vira inteiro, entao a opcao "410014" volta do
     * formulario como 410014, enquanto "000001" volta como texto por causa do
     * zero a esquerda.
     *
     * Recusar o inteiro era mudo e caro: 67 das 71 classificacoes e 14 dos 36
     * indicadores nao tem zero a esquerda. O campo do credito presumido nao
     * aparecia para classificacao nenhuma, nem para a unica que o permite, e a
     * explicacao do indicador caia sempre no texto generico.
     */
    /**
     * A NBS e escolha de quem emite, e a tela diz por que. Quando o subitem tem
     * um item so, dos 82 que tem, nao ha escolha a fazer e o texto avisa; quando
     * o codigo do servico ainda nao foi escolhido, nao ha o que oferecer.
     */
    private static function ajudaDaNbs(Get $get): string
    {
        $servicos = app(BuscaDeCodigosDeServico::class);
        $opcoes = count($servicos->nbsParaSelecao($get('codigo_servico')));

        return match (true) {
            $opcoes === 0 => __('O Anexo VIII não correlaciona nenhum item da NBS a este código de serviço.'),
            $opcoes === 1 => __('O Anexo VIII correlaciona um item só a este serviço, e ele já vem escolhido.'),
            default => __('Exigido quando a nota declara IBS/CBS (rejeição E0322). São :total itens correlacionados a este serviço pelo Anexo VIII.', ['total' => $opcoes]),
        };
    }

    private static function texto(mixed $estado): ?string
    {
        if (is_int($estado)) {
            return (string) $estado;
        }

        return is_string($estado) && $estado !== '' ? $estado : null;
    }

    /**
     * As aliquotas e a classificacao vem do cadastro do emitente, como o codigo
     * do servico e a aliquota do ISS. A copia acontece uma vez, na criacao:
     * mudar o cadastro depois nao reescreve nota ja aberta.
     */
    private static function padraoDoEmitente(string $campo): mixed
    {
        return app(PadroesDaEmpresa::class)->doEmitentePadrao($campo);
    }

    /**
     * @return array<int, mixed>
     */
    private static function totaisAproximados(): array
    {
        return [
            // Já vem "sim" quando o sistema consegue responder sozinho. A Lei
            // da Transparência diz que a informação "deverá constar", e o custo
            // dos dois erros é assimétrico: declarar onde não era exigido não
            // fere nada, omitir onde era exigido é infração sujeita às sanções
            // do CDC. Mas oferecer "sim" sem ter de onde tirar o número só
            // produziria uma nota que não grava até alguém digitar.
            self::pergunta('tem_totais', __('Informar os totais aproximados de tributos?'), padrao: self::sabeCalcularOsTotais(...))
                ->afterStateHydrated(fn (ToggleButtons $campo, ?Nota $record) => self::respostaDoRegistro(
                    $campo,
                    $record,
                    static fn (Nota $nota): bool => $nota->totaisAproximados() !== null,
                ))
                ->afterStateUpdated(function (bool $state, Set $set): void {
                    if ($state) {
                        return;
                    }

                    foreach (['federais', 'estaduais', 'municipais'] as $esfera) {
                        $set("total_tributos_{$esfera}", null);
                    }
                })
                ->helperText(__('Lei da Transparência: quanto de tributo há embutido no preço.')),

            // Quem está no Simples declara um percentual só. O DAS reúne IRPJ,
            // CSLL, PIS, COFINS, CPP e o próprio ISS numa guia, então não há o
            // que separar entre os três entes, e o leiaute tem campo próprio.
            Campos::percentual('percentual_simples_nacional', __('Alíquota efetiva do Simples Nacional'))
                ->columnSpan(6)
                ->rule(self::aliquotaDoSimplesInformada())
                ->visible(fn (Get $get): bool => (bool) $get('tem_totais') && self::emitenteNoSimples($get))
                ->helperText(__('Muda todo mês: sai da receita bruta dos últimos doze meses. A tabela do IBPT não serve para quem recolhe por guia única.')),

            // A ação vai num componente `Actions`, e não no `belowContent` do
            // campo: ali ela simplesmente não aparecia na tela, e um botão que
            // não renderiza é pior que botão nenhum, porque o texto de ajuda
            // embaixo do campo continua prometendo a tabela do IBPT.
            Actions::make([
                Action::make('calcularPelaTabelaDoIbpt')
                    ->label(__('Calcular pela tabela do IBPT'))
                    ->icon(Heroicon::OutlinedSparkles)
                    ->link()
                    ->action(self::preencherPelaTabela(...)),
            ])
                // A chave nomeia o bloco para quem precisa alcançá-lo de fora:
                // é por ela que o teste chega ao botão.
                ->key('carga-do-ibpt')
                ->columnSpan(12)
                ->visible(fn (Get $get): bool => (bool) $get('tem_totais') && ! self::emitenteNoSimples($get)),

            Campos::dinheiro('total_tributos_federais', __('Federais'))
                ->columnSpan(4)
                ->rule(self::algumTotalInformado())
                ->visible(fn (Get $get): bool => (bool) $get('tem_totais') && ! self::emitenteNoSimples($get))
                ->helperText(fn (Get $get): string => self::origemDaCarga($get)),

            Campos::dinheiro('total_tributos_estaduais', __('Estaduais'))
                ->columnSpan(4)
                ->rule(self::algumTotalInformado())
                ->visible(fn (Get $get): bool => (bool) $get('tem_totais') && ! self::emitenteNoSimples($get)),

            Campos::dinheiro('total_tributos_municipais', __('Municipais'))
                ->columnSpan(4)
                ->rule(self::algumTotalInformado())
                ->visible(fn (Get $get): bool => (bool) $get('tem_totais') && ! self::emitenteNoSimples($get)),
        ];
    }

    /**
     * "Sim" com os três zerados não declara nada: `TotaisAproximados` considera
     * o grupo vazio e ele some do JSON. Sem esta regra a resposta e o documento
     * discordam em silêncio, e quem preencheu acha que declarou.
     *
     * Zero também não é declaração honesta: a Lei da Transparência pede o
     * tributo embutido no preço, e serviço tributado tem algum.
     */
    private static function algumTotalInformado(): Closure
    {
        return static fn (Get $get): Closure => static function (string $atributo, mixed $valor, Closure $falhar) use ($get): void {
            if (! $get('tem_totais') || self::emitenteNoSimples($get)) {
                return;
            }

            $soma = 0.0;

            foreach (['federais', 'estaduais', 'municipais'] as $esfera) {
                $soma += self::valorDoCampo($get, "total_tributos_{$esfera}");
            }

            if ($soma <= 0) {
                $falhar(__('Informe ao menos um dos três totais, ou responda "Não": zerado, o grupo não sai na nota.'));
            }
        };
    }

    /**
     * Preenche os três totais a partir da TabelaIBPTax, pelo item da LC 116 e
     * pela UF do emitente. O percentual é sobre o preço, então a conta é o
     * valor do serviço vezes cada percentual.
     *
     * Os campos continuam editáveis depois: a Lei da Transparência aceita a
     * apuração do próprio contribuinte, e a tabela é um caminho, não o único.
     */
    private static function preencherPelaTabela(Get $get, Set $set): void
    {
        $carga = self::cargaDoServico($get);

        if ($carga === null) {
            Notification::make()
                ->warning()
                ->title(__('Sem carga tributária para este serviço'))
                ->body(__('A tabela do IBPT não tem o item :codigo para a UF do emitente. Informe os valores à mão.', [
                    'codigo' => self::texto($get('codigo_servico')) ?? '—',
                ]))
                ->send();

            return;
        }

        // O municipal sai do ISSQN desta nota, e não do percentual da tabela:
        // o porquê está no `totaisSobre()` da carga tributária. A prévia já
        // calcula esse número, com dedução, desconto e benefício aplicados.
        $issqn = PreviaDosValores::doFormulario(self::estadoDoCalculo($get))->issqn;

        $totais = $carga->totaisSobre(Dinheiro::deTexto(self::estado($get, 'valor_servico')), $issqn);

        // Texto formatado, e não número: ver `Campos::comoMoeda()`. Aqui o
        // defeito era latente, porque um total como 336,25 sobrevive à máscara
        // por coincidência, e um como 150,50 sairia como 15,05.
        $set('total_tributos_federais', Campos::comoMoeda($totais->federais->emReais()));
        $set('total_tributos_estaduais', Campos::comoMoeda($totais->estaduais->emReais()));
        $set('total_tributos_municipais', Campos::comoMoeda($totais->municipais->emReais()));
    }

    /**
     * A frase embaixo do campo: de onde o número veio, e de quando. A tabela do
     * IBPT vence a cada poucos meses, e emitir com percentual vencido descumpre
     * a lei que mandou destacá-lo, então a validade fica à vista.
     */
    private static function origemDaCarga(Get $get): string
    {
        $carga = self::cargaDoServico($get);

        if ($carga === null) {
            return __('Não é o ISS: é o tributo embutido no preço, e vem da tabela do IBPT pelo código do serviço.');
        }

        $ate = $carga->vigencia_fim?->format('d/m/Y') ?? '—';

        $validade = $carga->estaVencida()
            ? __('TABELA VENCIDA em :ate.', ['ate' => $ate])
            : __('vigente até :ate.', ['ate' => $ate]);

        // O municipal da tabela não entra na frase porque não é o que vai ser
        // preenchido: no lugar dele vai o ISSQN desta nota. Anunciar a média
        // estadual aqui prometeria um número que o botão não escreve.
        return __('Federal e estadual da tabela IBPT :versao (:federal e :estadual), :validade O municipal é o ISSQN desta nota.', [
            'versao' => $carga->versao,
            'federal' => $carga->federal()->formatada(),
            'estadual' => $carga->estadual()->formatada(),
            'validade' => $validade,
        ]);
    }

    /**
     * A UF é a do emitente, e não a do município da prestação: o IBPT publica
     * um arquivo por UF, e quem o baixa é a empresa que emite.
     */
    private static function cargaDoServico(Get $get): ?CargaTributariaAproximada
    {
        $empresa = app(EmpresasEmitentes::class)->encontrar($get('empresa_id'));

        return app(BuscaDeCargasTributarias::class)->paraServico(
            $get('codigo_servico'),
            $empresa?->cidade->uf,
        );
    }

    /**
     * O que o ISSQN desta nota depende. É menos que o formulário inteiro de
     * propósito: quem lê a lista sabe o que muda o número sem abrir a conta.
     *
     * @return array<string, mixed>
     */
    private static function estadoDoCalculo(Get $get): array
    {
        $campos = [
            'valor_servico', 'aliquota_iss', 'deducoes', 'desconto_incondicionado',
            'tributacao_issqn', 'numero_beneficio_municipal', 'percentual_reducao_base',
        ];

        return array_combine($campos, array_map(static fn (string $campo): mixed => $get($campo), $campos));
    }

    private static function estado(Get $get, string $campo): string
    {
        $valor = $get($campo);

        return is_string($valor) || is_float($valor) || is_int($valor) ? (string) $valor : '';
    }

    /**
     * O mesmo cuidado do outro lado: "sim" com a alíquota zerada não declara
     * nada, e `TotaisAproximados` trata o grupo como vazio.
     */
    private static function aliquotaDoSimplesInformada(): Closure
    {
        return static fn (Get $get): Closure => static function (string $atributo, mixed $valor, Closure $falhar) use ($get): void {
            if (! $get('tem_totais') || ! self::emitenteNoSimples($get)) {
                return;
            }

            if (Dinheiro::numeroDoTexto((string) (is_scalar($valor) ? $valor : '')) <= 0) {
                $falhar(__('Informe a alíquota efetiva do Simples, ou responda "Não": zerada, o grupo não sai na nota.'));
            }
        };
    }

    /**
     * Sugere o municipio do tomador como localidade de incidencia, que e a
     * regra geral do art. 11, X, da LC 214/2025.
     *
     * Chamado quando muda o tomador, quando muda o emitente e quando o
     * formulario abre. Substitui so o que ele mesmo sugeriu: municipio
     * escolhido a mao e decisao de quem emite, porque imovel, servico
     * presencial e evento puxam o local para onde a operacao acontece.
     *
     * Provedor que nao le o campo apaga a localidade, inclusive a escolhida a
     * mao: ela era do emitente anterior, e a revisao nao pode mostrar uma
     * localidade que nao vai na DPS. Com a API fora do ar nao se sabe o
     * provedor, e o campo fica como esta.
     */
    public static function sugerirLocalidadeDoTomador(Get $get, Set $set, mixed $tomadorAnterior = null): void
    {
        $provedor = self::provedorPrevisto($get);

        if (! $provedor->consultado()) {
            return;
        }

        if (! $provedor->exigeLocalidadeDeIncidencia()) {
            $set('cidade_incidencia_ibs_cbs_id', null);

            return;
        }

        $tomadores = app(ClientesTomadores::class);
        $atual = self::chave($get('cidade_incidencia_ibs_cbs_id'));
        $sugeridaAntes = $tomadores->cidadeDe(self::chave($tomadorAnterior ?? $get('cliente_id')));

        if ($atual !== null && (int) $atual !== $sugeridaAntes) {
            return;
        }

        $set('cidade_incidencia_ibs_cbs_id', $tomadores->cidadeDe(self::chave($get('cliente_id'))));
    }

    private static function perguntaALocalidade(Get $get): bool
    {
        return (bool) $get('tem_ibs_cbs') && self::provedorPrevisto($get)->exigeLocalidadeDeIncidencia();
    }

    /**
     * A mesma previsao da caixa do provedor, e com o mesmo cache: perguntar a
     * cada desenho nao sai para a rede. API fora do ar esconde o campo, como
     * esconde qualquer afirmacao sobre o municipio.
     */
    private static function provedorPrevisto(Get $get): ProvedorPrevisto
    {
        $empresa = app(EmpresasEmitentes::class)->encontrar(self::chave($get('empresa_id')));

        return $empresa === null
            ? ProvedorPrevisto::naoConsultado()
            : app(PreverProvedorDoMunicipio::class)->executar($empresa->municipio());
    }

    /**
     * Id vindo do formulario: inteiro, texto, ou nada. Texto vazio e nada.
     */
    private static function chave(mixed $estado): int|string|null
    {
        return is_int($estado) || (is_string($estado) && $estado !== '') ? $estado : null;
    }

    /**
     * O emitente já classifica IBS/CBS por padrão? Os três campos andam juntos,
     * então a resposta só é "sim" quando os três estão no cadastro.
     */
    private static function emitenteClassificaIbsCbs(): bool
    {
        foreach (['cst_ibs_cbs', 'indicador_de_operacao', 'classificacao_tributaria'] as $campo) {
            if (blank(self::padraoDoEmitente($campo))) {
                return false;
            }
        }

        return true;
    }

    /**
     * O sistema tem como preencher os totais sozinho? Só quando o emitente não
     * é do Simples, tem serviço padrão no cadastro, e a tabela do IBPT cobre
     * esse serviço na UF dele.
     *
     * O Simples fica de fora porque ali o número é a alíquota efetiva, que sai
     * da receita dos últimos doze meses e só o contribuinte sabe. Oferecer
     * "sim" seria abrir um campo obrigatório que ninguém pode preencher por ele.
     */
    private static function sabeCalcularOsTotais(): bool
    {
        $empresa = app(EmpresasEmitentes::class)->padrao();

        if ($empresa === null || $empresa->optaPeloSimplesNacional()) {
            return false;
        }

        return app(BuscaDeCargasTributarias::class)->paraServico(
            $empresa->codigo_servico_padrao,
            $empresa->cidade->uf,
        ) !== null;
    }

    /**
     * Preenche os totais quando eles ainda estão zerados, e só então: quem
     * digitou um valor à mão não pode vê-lo trocado ao mexer noutro campo.
     *
     * Chamado quando o valor do serviço muda, porque é dele que a conta
     * depende. Não avisa quando a tabela não tem o serviço: numa nota que está
     * sendo preenchida isso ainda não é problema, e o aviso viraria ruído a
     * cada campo preenchido. Quem clica no botão, esse sim ouve o motivo.
     */
    public static function preencherTotaisSeVazios(Get $get, Set $set): void
    {
        if (! $get('tem_totais') || self::emitenteNoSimples($get)) {
            return;
        }

        foreach (['federais', 'estaduais', 'municipais'] as $esfera) {
            if (self::valorDoCampo($get, "total_tributos_{$esfera}") > 0) {
                return;
            }
        }

        if (self::cargaDoServico($get) !== null) {
            self::preencherPelaTabela($get, $set);
        }
    }

    /**
     * Optante do Simples declara de um jeito, não optante de outro, e é o
     * emitente escolhido na nota que decide qual.
     */
    private static function emitenteNoSimples(Get $get): bool
    {
        return app(EmpresasEmitentes::class)->encontrar($get('empresa_id'))?->optaPeloSimplesNacional() ?? false;
    }

    private static function valorDoCampo(Get $get, string $campo): float
    {
        $estado = $get($campo);

        return is_string($estado) || is_float($estado) || is_int($estado)
            ? Dinheiro::deTexto($estado)->emReais()
            : 0.0;
    }

    /**
     * A pergunta em si. Nao e coluna, mas viaja no `$data`: sem ela o "Nao" e
     * indistinguivel de "o campo nao veio nesta atualizacao", e o dado antigo
     * sobrevive. Quem a remove antes de chegar ao model e TributacaoRespondida.
     */
    private static function pergunta(string $nome, string $rotulo, bool|Closure $padrao = false): ToggleButtons
    {
        return ToggleButtons::make($nome)
            ->label($rotulo)
            ->boolean(__('Sim'), __('Não'))
            ->inline()
            ->grouped()
            ->default($padrao)
            ->live()
            ->columnSpan(12);
    }

    /**
     * A resposta de uma nota que já existe sai do que está gravado nela; a de
     * uma nota nova fica com o padrão da pergunta.
     *
     * Sem a guarda do `$record === null`, a hidratação rodava na criação com
     * registro nenhum e respondia "não" a tudo, atropelando qualquer padrão que
     * a pergunta declarasse.
     *
     * @param  Closure(Nota): bool  $comoEstaGravado
     */
    private static function respostaDoRegistro(ToggleButtons $campo, ?Nota $record, Closure $comoEstaGravado): void
    {
        if ($record === null) {
            return;
        }

        $campo->state($comoEstaGravado($record));
    }
}
