<?php

declare(strict_types=1);

namespace App\Filament\Resources\Notas\Schemas;

use App\Actions\Municipios\PreverProvedorDoMunicipio;
use App\Actions\Municipios\ProvedorPrevisto;
use App\Consultas\BuscaDeCidades;
use App\Consultas\BuscaDeCodigosDeServico;
use App\Consultas\EmpresasEmitentes;
use App\Consultas\PadroesDaEmpresa;
use App\Consultas\PreviaDosValores;
use App\Consultas\RevisaoDaNota;
use App\Domain\Enums\TributacaoIssqn;
use App\Domain\ValueObjects\Dinheiro;
use App\Filament\Schemas\Campos;
use App\Filament\Schemas\PerguntasDeTributacao;
use App\Filament\Schemas\SeletorDeTomador;
use App\Models\Nota;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Os campos da nota sao definidos uma vez e apresentados de duas formas: em
 * etapas na criacao, onde a ordem importa e o usuario esta descobrindo o
 * caminho; em secoes na edicao, onde ele ja sabe o que quer mudar.
 */
class NotaForm
{
    /**
     * Os subitens que o art. 8º-A, §1º, da LC 116 tira do piso de 2%: obra
     * (7.02), reparação (7.05) e transporte municipal (16.01).
     *
     * @var list<string>
     */
    private const SUBITENS_SEM_PISO = ['07.02', '07.05', '16.01'];

    public static function configure(Schema $schema): Schema
    {
        // O container de uma pagina de recurso vem com 2 colunas. Sem fixar em
        // 1, as secoes se espremem lado a lado e os campos ficam com um terco
        // da largura.
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Partes'))
                    ->icon(Heroicon::OutlinedUsers)
                    ->description(__('Quem presta e quem recebe. Série, número e ambiente vêm do cadastro do emitente.'))
                    ->columns(12)
                    ->schema(self::partes()),

                Section::make(__('Serviço'))
                    ->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->description(__('O que foi prestado, onde e em que competência. O município da prestação pode ser diferente do município do emitente.'))
                    ->columns(12)
                    ->schema(self::servico()),

                Section::make(__('Valores e tributação'))
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->description(__('A responsabilidade pelo conteúdo fiscal é de quem emite: alíquota e valores são seus. A conta aparece enquanto você digita.'))
                    ->columns(12)
                    ->schema(self::valores()),
            ]);
    }

    /**
     * @return array<int, Step>
     */
    public static function passos(): array
    {
        return [
            Step::make(__('Partes'))
                ->description(__('Quem emite e para quem'))
                ->icon(Heroicon::OutlinedUsers)
                ->columns(12)
                ->schema(self::partes()),

            Step::make(__('Serviço'))
                ->description(__('O que foi prestado, onde e quando'))
                ->icon(Heroicon::OutlinedWrenchScrewdriver)
                ->columns(12)
                ->schema(self::servico()),

            Step::make(__('Valores'))
                ->description(__('Quanto e como tributa'))
                ->icon(Heroicon::OutlinedBanknotes)
                ->columns(12)
                ->schema(self::valores()),

            Step::make(__('Revisão'))
                ->description(__('Confira antes de emitir'))
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->columns(12)
                ->schema(self::revisao()),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function partes(): array
    {
        $empresas = app(EmpresasEmitentes::class);
        $padroes = app(PadroesDaEmpresa::class);

        return [
            Select::make('empresa_id')
                ->label(__('Emitente — prestador'))
                ->placeholder(__('Selecione a empresa que presta o serviço'))
                ->options(fn (): array => $empresas->paraSelecao())
                ->default(fn (): ?int => $empresas->padrao()?->getKey())
                ->searchable()
                ->native(false)
                ->required()
                ->live()
                ->afterStateUpdated(function (mixed $state, Set $set) use ($padroes): void {
                    foreach ($padroes->paraNota($state) as $campo => $valor) {
                        $set($campo, $valor);
                    }
                })
                ->columnSpan(6),

            SeletorDeTomador::campo()->columnSpan(6),

            // Quem recebe a DPS sai do municipio do emitente, e nao do da
            // prestacao. Sem isto so se descobre na transmissao, que e tarde:
            // municipio sem provedor recusa a nota inteira depois de ela estar
            // preenchida.
            //
            // Caixa com marca de estado, e nao mais uma linha de formulario: e
            // resposta que o sistema da, nao campo a preencher, e a diferenca
            // entre "atendido" e "vai recusar" nao pode depender de ler o texto.
            Placeholder::make('provedor_previsto')
                ->hiddenLabel()
                ->columnSpanFull()
                ->content(fn (Get $get): Htmlable => view(
                    'filament.notas.provedor',
                    self::provedorDoEmitente($get('empresa_id')),
                )),
        ];
    }

    /**
     * O que a caixa do provedor mostra. Vem inteiro de uma vez porque as tres
     * partes dependem do mesmo desfecho: municipio sem provedor precisa de cor,
     * icone e frase que digam a mesma coisa, e API fora do ar nao pode afirmar
     * nada sobre o municipio.
     *
     * @return array{estado: string, icone: Heroicon, descricao: string, explicacao: string}
     */
    private static function provedorDoEmitente(mixed $empresaId): array
    {
        $empresa = app(EmpresasEmitentes::class)->encontrar(
            is_int($empresaId) || is_string($empresaId) ? $empresaId : null
        );

        if ($empresa === null) {
            return [
                'estado' => 'sem-emitente',
                'icone' => Heroicon::OutlinedBuildingOffice2,
                'descricao' => __('Ainda não há emitente escolhido'),
                'explicacao' => __('É o município do emitente que decide para onde a DPS vai.'),
            ];
        }

        $previsto = app(PreverProvedorDoMunicipio::class)->executar($empresa->municipio());

        return [
            ...self::estadoDoProvedor($previsto),
            'descricao' => "{$empresa->cidade->nomeComUf()} · {$previsto->descricao()}",
        ];
    }

    /**
     * @return array{estado: string, icone: Heroicon, explicacao: string}
     */
    private static function estadoDoProvedor(ProvedorPrevisto $previsto): array
    {
        if (! $previsto->consultado()) {
            return [
                'estado' => 'indisponivel',
                'icone' => Heroicon::OutlinedQuestionMarkCircle,
                'explicacao' => __('A API fiscal não respondeu. Não impede preencher nem gravar: o provedor só é exigido na transmissão.'),
            ];
        }

        if (! $previsto->atendido()) {
            return [
                'estado' => 'sem-provedor',
                'icone' => Heroicon::OutlinedExclamationTriangle,
                'explicacao' => __('A transmissão vai recusar. O município do emitente precisa ser um dos atendidos pela API fiscal.'),
            ];
        }

        return [
            'estado' => 'atendido',
            'icone' => Heroicon::OutlinedCheckBadge,
            'explicacao' => __('Vem do município do emitente, e não do município da prestação.'),
        ];
    }

    /**
     * O que o emitente configurou vem preenchido, nao so quando se troca de
     * emitente: `afterStateUpdated` nao dispara no default, entao o formulario
     * abria vazio com um placeholder que parecia valor.
     */
    private static function padraoDoEmitente(string $campo): mixed
    {
        return app(PadroesDaEmpresa::class)->doEmitentePadrao($campo);
    }

    /**
     * @return array<int, mixed>
     */
    private static function servico(): array
    {
        $cidades = app(BuscaDeCidades::class);
        $servicos = app(BuscaDeCodigosDeServico::class);

        return [
            DatePicker::make('competencia')
                ->label(__('Competência'))
                ->native(false)
                ->displayFormat('m/Y')
                ->default(now()->startOfMonth())
                ->required()
                ->columnSpan(3),

            Select::make('cidade_prestacao_id')
                ->label(__('Município da prestação'))
                ->placeholder(__('Onde o serviço foi executado'))
                ->required()
                ->searchable()
                ->native(false)
                ->columnSpan(9)
                ->default(fn (): mixed => self::padraoDoEmitente('cidade_prestacao_id'))
                ->helperText(__('Vem do município do emitente; mude se o serviço foi prestado em outro.'))
                ->getSearchResultsUsing(fn (string $search): array => $cidades->procurar($search))
                ->getOptionLabelUsing(fn (mixed $value): ?string => $cidades->rotuloDe($value)),

            Textarea::make('descricao_servico')
                ->label(__('Discriminação do serviço'))
                ->placeholder(__('Descreva o que foi prestado, como sairá na nota.'))
                ->required()
                ->rows(3)
                ->columnSpanFull(),

            // O cTribNac carrega o subitem da LC 116 nos quatro primeiros
            // dígitos, então escolher o código já responde o campo de baixo. Os
            // dois ficavam livres e pareciam a mesma coisa: "01.07" servia nos
            // dois, e só um deles estava certo.
            Select::make('codigo_servico')
                ->label(__('Código do serviço — cTribNac'))
                ->placeholder(__('Busque pelo código ou pelo serviço prestado'))
                ->required()
                ->searchable()
                ->native(false)
                ->live()
                ->columnSpanFull()
                ->options(fn (?Nota $record): array => $servicos->codigosParaSelecao($record?->codigo_servico))
                ->getSearchResultsUsing(fn (string $search): array => $servicos->procurarCodigos($search))
                ->getOptionLabelUsing(fn (mixed $value, ?Nota $record): ?string => $servicos->rotuloDeCodigo($value, $record?->codigo_servico))
                ->default(fn (): mixed => self::padraoDoEmitente('codigo_servico'))
                // Substitui só o que este campo mesmo preencheu, como o botão
                // dos totais aproximados: item escolhido à mão fica de pé,
                // porque provedor ABRASF às vezes pede outro e isso é decisão
                // de quem emite, não deste seletor.
                ->afterStateUpdated(function (mixed $state, mixed $old, Get $get, Set $set) use ($servicos): void {
                    $atual = $get('item_lista_servico');

                    if (blank($atual) || $atual === $servicos->itemImplicadoPor($old)) {
                        $set('item_lista_servico', $servicos->itemImplicadoPor($state));
                    }

                    // A NBS do serviço anterior não vale para o novo: os itens
                    // são correlacionados subitem por subitem. Quando o Anexo
                    // VIII dá um item só, ele já vem escolhido; quando dá vários,
                    // o campo esvazia para quem emite escolher.
                    if ($servicos->nbsParaSelecao($old) !== $servicos->nbsParaSelecao($state)) {
                        $set('nbs', $servicos->nbsUnicaDoCodigo($state));
                    }
                })
                ->helperText(fn (Get $get): string => $servicos->descricaoDoCodigo($get('codigo_servico'))
                    ?? self::origemDoPadrao('codigo_servico', __('São os seis dígitos da tabela nacional: o subitem da LC 116 mais o desdobramento.'))),

            Select::make('item_lista_servico')
                ->label(__('Item da lista — ABRASF'))
                ->placeholder(__('Vem do código do serviço'))
                ->searchable()
                ->native(false)
                ->columnSpan(8)
                ->options(fn (?Nota $record): array => $servicos->itensParaSelecao($record?->item_lista_servico))
                ->getSearchResultsUsing(fn (string $search): array => $servicos->procurarItens($search))
                ->getOptionLabelUsing(fn (mixed $value, ?Nota $record): ?string => $servicos->rotuloDeItem($value, $record?->item_lista_servico))
                ->default(fn (): mixed => self::padraoDoEmitente('item_lista_servico'))
                ->helperText(__('Preenchido a partir do código do serviço. Ignorado no Padrão Nacional.')),

            TextInput::make('cnae')
                ->label(__('CNAE'))
                ->placeholder('6201501')
                ->columnSpan(4)
                ->default(fn (): mixed => self::padraoDoEmitente('cnae')),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function valores(): array
    {
        return [
            Campos::dinheiro('valor_servico', __('Valor do serviço'))
                ->required()
                ->rule(Campos::valorMinimo(0.01, __('O valor do serviço precisa ser maior que zero.')))
                ->live(onBlur: true)
                // Os totais aproximados são percentuais sobre este valor, então
                // é aqui que eles passam a ser calculáveis. Preenche só o que
                // ainda está zerado: número digitado à mão não é substituído.
                ->afterStateUpdated(PerguntasDeTributacao::preencherTotaisSeVazios(...))
                ->rule(self::abatimentosDentroDoServico())
                ->columnSpan(4),

            // Só é exigida onde há alíquota municipal a declarar, e quem
            // responde isso é `ValoresDoServico::declaraAliquota()`: a mesma
            // pergunta que decide o `pAliq` da DPS, para a tela não poder
            // discordar do documento.
            //
            // Fica de fora nas três tributações sem imposto, e no ME/EPP que
            // apura o ISSQN pelo Simples sem retenção, que é a rejeição E0625.
            Campos::percentual('aliquota_iss', __('Alíquota do ISS'))
                ->required(fn (Get $get): bool => self::declaraAliquota($get))
                ->visible(fn (Get $get): bool => self::declaraAliquota($get))
                ->live(onBlur: true)
                ->columnSpan(4)
                ->default(fn (): mixed => self::padraoDoEmitente('aliquota_iss'))
                ->helperText(fn (Get $get): string => self::avisoDaAliquota($get)
                    ?? self::origemDoPadrao('aliquota_iss', '')),

            Text::make(fn (Get $get): string => (string) self::explicacaoDaAliquotaAusente($get))
                ->color('gray')
                ->columnSpan(4)
                ->visible(fn (Get $get): bool => self::explicacaoDaAliquotaAusente($get) !== null),

            Campos::dinheiro('deducoes', __('Deduções'))
                ->live(onBlur: true)
                ->rule(self::abatimentosDentroDoServico())
                ->columnSpan(4)
                ->helperText(__('Da base por força da LC 116 sai só o material dos subitens 7.02 e 7.05 (art. 7º, §2º, I). O resto depende da lei do município.')),

            Campos::dinheiro('desconto_incondicionado', __('Desconto incondicionado'))
                ->live(onBlur: true)
                ->rule(self::abatimentosDentroDoServico())
                ->columnSpan(3)
                ->helperText(__('Sai da base de cálculo.')),

            Campos::dinheiro('desconto_condicionado', __('Desconto condicionado'))
                ->live(onBlur: true)
                ->columnSpan(3)
                ->helperText(__('Não altera a base, mas sai do líquido.')),

            Select::make('tributacao_issqn')
                ->label(__('Tributação do ISSQN sobre o serviço'))
                ->options(TributacaoIssqn::class)
                ->default(TributacaoIssqn::OperacaoTributavel)
                ->selectablePlaceholder(false)
                ->native(false)
                ->required()
                ->live()
                ->columnSpan(6),

            ...PerguntasDeTributacao::campos(),

            self::previa(),
        ];
    }

    private static function operacaoTributavel(Get $get): bool
    {
        $estado = $get('tributacao_issqn');

        $tributacao = $estado instanceof TributacaoIssqn
            ? $estado
            : TributacaoIssqn::tryFrom(is_numeric($estado) ? (int) $estado : 0);

        return $tributacao?->temIssqnDevido() ?? false;
    }

    /**
     * A mesma pergunta que decide o `pAliq` da DPS, feita pela prévia, que já
     * traduz o estado do formulário. A regra mora em `ValoresDoServico`: repetir
     * aqui deixaria a tela e o documento livres para discordar.
     */
    private static function declaraAliquota(Get $get): bool
    {
        return self::previaDe($get)->declaraAliquota;
    }

    /**
     * O piso e o teto que a LC 116 fixa: máximo de 5% no art. 8º, II, e mínimo
     * de 2% no art. 8º-A.
     *
     * É aviso, e não trava, porque o próprio art. 8º-A, §1º, abre exceção para
     * os subitens 7.02, 7.05 e 16.01, e porque quem responde pela alíquota é a
     * lei do município, que este sistema não conhece. Nesses três subitens o
     * aviso do piso não aparece.
     */
    /**
     * Por que a alíquota não está sendo pedida. A ausência do campo por si só
     * não explica nada, e o operador de ME/EPP no Simples precisa saber que o
     * ISSQN sai na guia única, e não que o sistema esqueceu de perguntar.
     */
    private static function explicacaoDaAliquotaAusente(Get $get): ?string
    {
        if (self::declaraAliquota($get) || ! self::operacaoTributavel($get)) {
            return null;
        }

        return __('O ISSQN deste emitente sai na guia única do Simples: a DPS não pode informar alíquota (rejeição E0625). Havendo retenção pelo tomador, o campo volta.');
    }

    private static function avisoDaAliquota(Get $get): ?string
    {
        $estado = $get('aliquota_iss');

        if (! is_string($estado) && ! is_float($estado) && ! is_int($estado)) {
            return null;
        }

        $aliquota = Dinheiro::numeroDoTexto((string) $estado);

        if ($aliquota <= 0.0) {
            return null;
        }

        if ($aliquota > 5.0) {
            return __('Acima do teto de 5% da LC 116, art. 8º, II.');
        }

        $subitem = app(BuscaDeCodigosDeServico::class)->itemImplicadoPor($get('codigo_servico'));

        if ($aliquota < 2.0 && ! in_array($subitem, self::SUBITENS_SEM_PISO, true)) {
            return __('Abaixo do piso de 2% da LC 116, art. 8º-A. Confira a lei do município.');
        }

        return null;
    }

    /**
     * Deduções e desconto incondicionado saem da base de cálculo: juntos, não
     * podem passar do valor do serviço. Sem isto a nota grava, a prévia mostra
     * um ISSQN negativo e o erro só aparece ao montar a DPS, longe do campo
     * que o causou.
     *
     * A regra vale nos três campos porque qualquer um deles pode quebrá-la:
     * subir a dedução ou baixar o serviço dá no mesmo.
     */
    private static function abatimentosDentroDoServico(): Closure
    {
        return static fn (Get $get): Closure => static function (string $atributo, mixed $valor, Closure $falhar) use ($get): void {
            $servico = self::valorDoCampo($get, 'valor_servico');
            $abatimentos = self::valorDoCampo($get, 'deducoes')
                ->somar(self::valorDoCampo($get, 'desconto_incondicionado'));

            if ($abatimentos->centavos <= $servico->centavos) {
                return;
            }

            $falhar(__(
                'Deduções e desconto incondicionado somam :abatimentos, mais que o serviço (:servico). '
                .'A base de cálculo ficaria negativa.',
                ['abatimentos' => $abatimentos->formatado(), 'servico' => $servico->formatado()],
            ));
        };
    }

    private static function valorDoCampo(Get $get, string $campo): Dinheiro
    {
        $estado = $get($campo);

        return Dinheiro::deTexto(
            is_string($estado) || is_float($estado) || is_int($estado) ? $estado : null
        );
    }

    /**
     * Diz de onde o valor veio. Quem abre o formulario pela primeira vez nao
     * sabe que aquele numero saiu do cadastro do emitente, e vai procurar.
     */
    private static function origemDoPadrao(string $campo, string $complemento): string
    {
        $padrao = self::padraoDoEmitente($campo);

        $origem = blank($padrao) ? '' : __('Sugerido pelo cadastro do emitente.');

        return trim($origem.' '.$complemento);
    }

    /**
     * A ultima etapa e so leitura: e onde se confere o que foi preenchido, com
     * o imposto ja calculado, antes de qualquer coisa sair. Espelha a etapa
     * "Emitir NFS-e" do Emissor Nacional, para corrigir, basta voltar pelo
     * cabecalho do assistente.
     *
     * @return array<int, mixed>
     */
    private static function revisao(): array
    {
        return [
            Text::make(__('Revise a DPS e confira o cálculo do imposto. Para mudar algo, volte pelas etapas no topo. Nada é transmitido até você mandar.'))
                ->color('gray')
                ->columnSpanFull(),

            self::bloco('Partes', Heroicon::OutlinedUsers, 5, fn (RevisaoDaNota $revisao, array $estado): array => [
                /** @var view-string */
                'view' => 'filament.notas.revisao-pessoas',
                'dados' => ['partes' => $revisao->pessoas($estado)],
            ]),

            self::bloco('Serviço', Heroicon::OutlinedWrenchScrewdriver, 7, fn (RevisaoDaNota $revisao, array $estado): array => [
                'view' => 'filament.notas.revisao-servico',
                'dados' => $revisao->servico($estado),
            ]),

            self::bloco('Tributação municipal', Heroicon::OutlinedScale, 5, fn (RevisaoDaNota $revisao, array $estado): array => [
                'view' => 'filament.notas.revisao-tributacao',
                'dados' => ['linhas' => $revisao->tributacao($estado)],
            ]),

            self::bloco('Prévia dos valores da NFS-e', Heroicon::OutlinedCalculator, 7, fn (RevisaoDaNota $revisao, array $estado): array => [
                'view' => 'filament.notas.revisao-conta',
                'dados' => ['linhas' => $revisao->previa($estado)],
            ]),
        ];
    }

    /**
     * Cada bloco pede o seu pedaco do resumo a consulta, ja formatado, e escolhe
     * a view que sabe desenha-lo: a tela nao calcula nem busca nada.
     *
     * @param  callable(RevisaoDaNota, array<string, mixed>): array{view: view-string, dados: array<string, mixed>}  $montar
     */
    private static function bloco(string $titulo, Heroicon $icone, int $largura, callable $montar): Section
    {
        return Section::make(__($titulo))
            ->icon($icone)
            ->columnSpan($largura)
            ->schema([
                Placeholder::make('revisao_'.str($titulo)->slug('_'))
                    ->hiddenLabel()
                    ->content(function (Get $get) use ($montar): Htmlable {
                        $bloco = $montar(app(RevisaoDaNota::class), self::estadoDaNota($get));

                        return view($bloco['view'], $bloco['dados']);
                    }),
            ]);
    }

    /**
     * O estado que os blocos de revisao consomem. Ler campo a campo em vez de
     * pegar o formulario inteiro deixa explicito de que a revisao depende.
     *
     * @return array<string, mixed>
     */
    private static function estadoDaNota(Get $get): array
    {
        $campos = [
            'empresa_id', 'cliente_id', 'cidade_prestacao_id', 'competencia',
            'descricao_servico', 'codigo_servico', 'cnae', 'item_lista_servico',
            'valor_servico', 'aliquota_iss', 'deducoes',
            'desconto_incondicionado', 'desconto_condicionado',
            'tributacao_issqn', 'retencao_issqn',
            'cst_pis_cofins', 'aliquota_pis', 'aliquota_cofins',
            'aliquota_csll', 'aliquota_irrf', 'aliquota_previdenciaria',
            'contribuicoes_retidas',
            'cst_ibs_cbs', 'indicador_de_operacao', 'classificacao_tributaria',

            // As perguntas viajam junto com os campos que elas governam: campo
            // escondido não perde o valor, e sem a resposta a revisão mostraria
            // o padrão do emitente como se fosse declaração desta nota.
            'tem_retencao_federal', 'tem_ibs_cbs',
            'percentual_simples_nacional',
            'numero_beneficio_municipal', 'percentual_reducao_base',
            'tipo_suspensao', 'numero_processo_suspensao',
        ];

        return array_combine($campos, array_map(static fn (string $campo): mixed => $get($campo), $campos));
    }

    /**
     * A conta aparece enquanto se digita. Quem calcula continua sendo o dominio:
     * aqui so se le o estado do formulario e se mostra o resultado.
     */
    private static function previa(): Grid
    {
        return Grid::make(3)
            ->columnSpanFull()
            ->schema([
                self::valorCalculado('base_de_calculo', __('Base de cálculo'), __('Serviço − deduções − desconto incondicionado'))
                    ->content(fn (Get $get): string => self::previaDe($get)->baseDeCalculo->formatado()),

                self::valorCalculado('issqn', __('ISSQN'), __('Base × alíquota, quando a operação é tributável'))
                    ->content(fn (Get $get): string => self::previaDe($get)->issqn->formatado()),

                self::valorCalculado('liquido', __('Líquido para o prestador'), __('Serviço − descontos − retenções, federais e o ISSQN quando retido'))
                    ->content(fn (Get $get): string => self::previaDe($get)->liquido->formatado()),
            ]);
    }

    private static function valorCalculado(string $nome, string $rotulo, string $explicacao): Placeholder
    {
        return Placeholder::make($nome)->label($rotulo)->helperText($explicacao);
    }

    private static function previaDe(Get $get): PreviaDosValores
    {
        return PreviaDosValores::doFormulario(self::estadoDaNota($get));
    }
}
