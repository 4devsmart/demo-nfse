<?php

declare(strict_types=1);

namespace App\Filament\Resources\Empresas\Schemas;

use App\Actions\Cadastros\CadastroEncontrado;
use App\Consultas\BuscaDeClassificacoes;
use App\Consultas\BuscaDeCodigosDeServico;
use App\Consultas\BuscaDeIndicadores;
use App\Consultas\NumeracaoDaEmpresa;
use App\Domain\Enums\Ambiente;
use App\Domain\Enums\RegimeDeApuracaoDoSimples;
use App\Domain\Enums\RegimeEspecialTributacao;
use App\Domain\Enums\RegimeSimplesNacional;
use App\Domain\Enums\SituacaoTributariaPisCofins;
use App\Filament\Schemas\Campos;
use App\Filament\Schemas\CamposDeEndereco;
use App\Filament\Schemas\ConsultaDeCnpj;
use App\Models\Empresa;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Secoes com a explicacao a esquerda e os campos a direita. Cada bloco responde
 * a uma pergunta, e o texto que justifica o campo fica fora do caminho de quem
 * so quer preencher.
 */
class EmpresaForm
{
    /**
     * Os percentuais que a lei fixa para servico prestado por pessoa juridica a
     * pessoa juridica do regime normal. A CSRF de 4,65% se divide em CSLL 1%,
     * COFINS 3% e PIS 0,65% (IN RFB 459/2004); o IRRF de 1,5% e o do art. 714 do
     * RIR/2018 para servico profissional; os 11% da previdenciaria valem para
     * cessao de mao de obra.
     *
     * Sao ponto de partida do botao "usar os percentuais de lei", nao valor
     * padrao do campo: quem nao sofre retencao nenhuma nao deve encontrar o
     * cadastro ja preenchido com aliquota.
     *
     * @var array<string, float>
     */
    private const PERCENTUAIS_DE_LEI = [
        'aliquota_pis_padrao' => 0.65,
        'aliquota_cofins_padrao' => 3.0,
        'aliquota_csll_padrao' => 1.0,
        'aliquota_irrf_padrao' => 1.5,
        'aliquota_previdenciaria_padrao' => 11.0,
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                self::identificacao(),
                CamposDeEndereco::secao(),
                self::tributacao(),
                self::emissao(),
                self::retencoesFederais(),
                self::reformaTributaria(),
                self::certificado(),
                self::credenciaisDaPrefeitura(),
            ]);
    }

    private static function identificacao(): Section
    {
        return Section::make(__('Identificação'))
            ->icon(Heroicon::OutlinedIdentification)
            ->description(__('Quem presta o serviço e assina a nota. A lupa do CNPJ traz da Receita a razão social, o endereço e o CNAE principal.'))
            ->aside()
            ->columns(12)
            ->schema([
                TextInput::make('razao_social')
                    ->label(__('Razão social'))
                    ->placeholder(__('Como consta no CNPJ'))
                    ->required()
                    ->columnSpan(8),

                // O CNAE principal vive na secao de padroes de emissao, e a
                // consulta o alcanca daqui: quem cadastra o emitente digita o
                // CNPJ uma vez e nao vai atras do CNAE em outro lugar.
                Campos::cnpj()
                    ->suffixAction(ConsultaDeCnpj::botao(fn (CadastroEncontrado $cadastro): array => [
                        'nome_fantasia' => $cadastro->nomeFantasia,
                        'cnae_padrao' => $cadastro->cnaePrincipal,
                    ]))
                    ->columnSpan(4),

                TextInput::make('nome_fantasia')->label(__('Nome fantasia'))->columnSpan(8),

                TextInput::make('inscricao_municipal')
                    ->label(__('Inscrição municipal'))
                    ->columnSpan(4),
            ]);
    }

    private static function tributacao(): Section
    {
        return Section::make(__('Regime tributário'))
            ->icon(Heroicon::OutlinedScale)
            ->description(__('Obrigatório: sem estes dois campos a nota não é montada.'))
            ->aside()
            ->columns(12)
            ->schema([
                Select::make('regime_simples_nacional')
                    ->label(__('Simples Nacional'))
                    ->options(RegimeSimplesNacional::class)
                    ->default(RegimeSimplesNacional::NaoOptante)
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        if (! self::declaraApuracaoDoSimples($state)) {
                            $set('regime_apuracao_simples', null);
                        }
                    })
                    ->columnSpan(6),

                // Só para ME/EPP, e é medição, não escolha de gosto: com o
                // campo preenchido a biblioteca fiscal grava `regApTribSN` no
                // XML do ME/EPP e descarta o do MEI. Oferecê-lo ao MEI seria um
                // controle que não muda nada.
                //
                // É ele quem paga a conta de o campo não existir: sem resposta,
                // a biblioteca grava "apura pelo Simples" por conta própria.
                Select::make('regime_apuracao_simples')
                    ->label(__('Apuração do ISSQN no Simples'))
                    ->options(RegimeDeApuracaoDoSimples::class)
                    ->default(RegimeDeApuracaoDoSimples::FederaisEMunicipalPeloSimples)
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->required()
                    ->visible(fn (Get $get): bool => self::declaraApuracaoDoSimples($get('regime_simples_nacional')))
                    ->columnSpan(6)
                    ->helperText(__('Município que exige o ISSQN por fora da guia única muda esta resposta.')),

                Select::make('regime_especial')
                    ->label(__('Regime especial'))
                    ->options(RegimeEspecialTributacao::class)
                    ->default(RegimeEspecialTributacao::Nenhum)
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->required()
                    ->columnSpan(6),
            ]);
    }

    private static function emissao(): Section
    {
        $servicos = app(BuscaDeCodigosDeServico::class);

        return Section::make(__('Padrões de emissão'))
            ->icon(Heroicon::OutlinedDocumentText)
            ->description(__('O ponto de partida de cada nota, que dá para mudar em qualquer emissão. Numeração e série são deste sistema: a API fiscal não controla nenhuma das duas.'))
            ->aside()
            ->columns(12)
            ->schema([
                Select::make('ambiente')
                    ->label(__('Ambiente'))
                    ->options(Ambiente::class)
                    ->default(Ambiente::Homologacao)
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->required()
                    ->columnSpan(4)
                    ->helperText(__('Homologação não tem valor fiscal.')),

                TextInput::make('serie_dps')
                    ->label(__('Série da DPS'))
                    ->default('1')
                    ->required()
                    ->maxLength(5)
                    ->live(onBlur: true)
                    ->columnSpan(4),

                TextInput::make('proximo_numero_dps')
                    ->label(__('Próximo número'))
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required()
                    ->columnSpan(4)
                    ->rule(static function (Get $get, ?Empresa $record): Closure {
                        return static function (string $atributo, mixed $valor, Closure $falhar) use ($get, $record): void {
                            $ultimo = app(NumeracaoDaEmpresa::class)
                                ->ultimoNumeroUsado($record?->getKey(), $get('serie_dps'));

                            if ($ultimo !== null && (int) $valor <= $ultimo) {
                                $falhar(__('A série já chegou ao número :ultimo. Voltar o contador repetiria documento fiscal.', ['ultimo' => $ultimo]));
                            }
                        };
                    })
                    ->helperText(fn (Get $get, ?Empresa $record): ?string => self::ondeASerieChegou($get, $record)),

                // Mesma dupla da nota, e pelo mesmo motivo: o subitem sai dos
                // quatro primeiros dígitos do cTribNac, então escolher um
                // preenche o outro.
                Select::make('codigo_servico_padrao')
                    ->label(__('Código do serviço — cTribNac'))
                    ->placeholder(__('Busque pelo código ou pelo serviço prestado'))
                    ->searchable()
                    ->native(false)
                    ->live()
                    ->columnSpan(8)
                    ->options(fn (?Empresa $record): array => $servicos->codigosParaSelecao($record?->codigo_servico_padrao))
                    ->getSearchResultsUsing(fn (string $search): array => $servicos->procurarCodigos($search))
                    ->getOptionLabelUsing(fn (mixed $value, ?Empresa $record): ?string => $servicos->rotuloDeCodigo($value, $record?->codigo_servico_padrao))
                    // Mesma regra da nota: só substitui o que este campo mesmo
                    // preencheu, e nunca o item escolhido à mão.
                    ->afterStateUpdated(function (mixed $state, mixed $old, Get $get, Set $set) use ($servicos): void {
                        $atual = $get('item_lista_servico_padrao');

                        if (blank($atual) || $atual === $servicos->itemImplicadoPor($old)) {
                            $set('item_lista_servico_padrao', $servicos->itemImplicadoPor($state));
                        }

                        if ($servicos->nbsParaSelecao($old) !== $servicos->nbsParaSelecao($state)) {
                            $set('nbs_padrao', $servicos->nbsUnicaDoCodigo($state));
                        }
                    })
                    ->helperText(fn (Get $get): ?string => $servicos->descricaoDoCodigo($get('codigo_servico_padrao'))),

                TextInput::make('cnae_padrao')
                    ->label(__('CNAE'))
                    ->placeholder('6201501')
                    ->columnSpan(4),

                Campos::percentual('aliquota_iss_padrao', __('Alíquota do ISS'))->columnSpan(4),

                // A NBS é exigida quando a nota declara IBS/CBS (rejeição
                // E0322). Fica aqui pelo mesmo motivo do CNAE: é atributo do
                // serviço que a empresa presta, e não decisão de cada nota.
                Select::make('nbs_padrao')
                    ->label(__('Item da NBS'))
                    ->placeholder(__('Escolha o código do serviço primeiro'))
                    ->options(fn (Get $get): array => $servicos->nbsParaSelecao($get('codigo_servico_padrao')))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => $servicos->rotuloDaNbs($value))
                    ->searchable()
                    ->native(false)
                    ->columnSpan(12)
                    ->helperText(__('Correlacionado ao subitem da LC 116 pelo Anexo VIII do RTC. A nota o herda e pode trocar.')),

                Select::make('item_lista_servico_padrao')
                    ->label(__('Item da lista — ABRASF'))
                    ->placeholder(__('Vem do código do serviço'))
                    ->searchable()
                    ->native(false)
                    ->columnSpan(8)
                    ->options(fn (?Empresa $record): array => $servicos->itensParaSelecao($record?->item_lista_servico_padrao))
                    ->getSearchResultsUsing(fn (string $search): array => $servicos->procurarItens($search))
                    ->getOptionLabelUsing(fn (mixed $value, ?Empresa $record): ?string => $servicos->rotuloDeItem($value, $record?->item_lista_servico_padrao))
                    ->helperText(__('Só é lido por provedores fora do Padrão Nacional.')),
            ]);
    }

    /**
     * As aliquotas de retencao federal do emitente. A nota as copia na criacao,
     * e a partir dai elas sao dela: mudar o cadastro nao reescreve nota antiga.
     *
     * Quais tributos o tomador de fato retem e pergunta da nota, nao daqui. O
     * mesmo prestador tem tomador que retem e tomador que nao retem.
     */
    private static function retencoesFederais(): Section
    {
        return Section::make(__('Retenções federais'))
            // A chave nomeia a seção para quem precisa alcançá-la de fora: é
            // por ela que o teste chega ao botão do cabeçalho.
            ->key('retencoes-federais')
            ->icon(Heroicon::OutlinedBanknotes)
            ->description(__('Os percentuais que o tomador retém quando há retenção. Praticamente não mudam, então ficam aqui em vez de serem redigitados a cada nota.'))
            ->aside()
            ->collapsed()
            ->columns(12)
            ->afterHeader([
                Action::make('usarPercentuaisDeLei')
                    ->label(__('Usar os percentuais de lei'))
                    ->icon(Heroicon::OutlinedSparkles)
                    ->link()
                    // O `$set` manda o texto já formatado, e não o número: a
                    // máscara do campo é quem manda depois de um preenchimento
                    // por código, e ela lê `0.65` como dígitos e mostra `65`.
                    ->action(function (Set $set): void {
                        foreach (self::PERCENTUAIS_DE_LEI as $campo => $percentual) {
                            $set($campo, Campos::comoPercentual($percentual));
                        }
                    }),
            ])
            ->schema([
                Select::make('cst_pis_cofins_padrao')
                    ->label(__('CST do PIS/COFINS'))
                    ->options(SituacaoTributariaPisCofins::deSaida())
                    ->native(false)
                    ->columnSpan(12)
                    ->helperText(__('Só aparecem as situações de saída: as de entrada e de crédito descrevem quem adquire, não quem presta.')),

                Campos::percentual('aliquota_pis_padrao', __('PIS'))
                    ->columnSpan(4),

                Campos::percentual('aliquota_cofins_padrao', __('COFINS'))
                    ->columnSpan(4),

                Campos::percentual('aliquota_csll_padrao', __('CSLL'))
                    ->columnSpan(4),

                Campos::percentual('aliquota_irrf_padrao', __('IRRF'))
                    ->columnSpan(4),

                Campos::percentual('aliquota_previdenciaria_padrao', __('Previdenciária'))
                    ->columnSpan(4)
                    ->helperText(__('Só na cessão de mão de obra.')),
            ]);
    }

    /**
     * O par CST + `cClassTrib` do grupo `ibscbs`. A DPS so classifica: quem
     * calcula IBS e CBS e a Sefin Nacional, na autorizacao.
     */
    private static function reformaTributaria(): Section
    {
        $classificacoes = app(BuscaDeClassificacoes::class);
        $indicadores = app(BuscaDeIndicadores::class);

        return Section::make(__('IBS e CBS'))
            ->icon(Heroicon::OutlinedScale)
            ->description(__('A classificação tributária da Reforma, que a nota herda daqui. Escolha o CST e depois a classificação.'))
            ->aside()
            ->collapsed()
            ->columns(12)
            ->schema([
                Select::make('cst_ibs_cbs_padrao')
                    ->label(__('CST do IBS/CBS'))
                    ->options(fn (): array => $classificacoes->situacoesTributarias())
                    ->native(false)
                    ->live()
                    ->columnSpan(5)
                    ->afterStateUpdated(fn (Set $set) => $set('classificacao_tributaria_padrao', null)),

                Select::make('classificacao_tributaria_padrao')
                    ->label(__('Classificação tributária'))
                    ->placeholder(__('Escolha o CST primeiro'))
                    ->options(fn (Get $get): array => $classificacoes->paraSelecao(self::texto($get('cst_ibs_cbs_padrao'))))
                    ->native(false)
                    ->searchable()
                    ->columnSpan(7)
                    ->helperText(__('Só os códigos válidos para NFS-e aparecem.')),

                Select::make('indicador_de_operacao_padrao')
                    ->label(__('Indicador da operação'))
                    ->options(fn (): array => $indicadores->paraSelecao())
                    ->native(false)
                    ->searchable()
                    ->live()
                    ->columnSpan(12)
                    ->helperText(fn (Get $get): string => $indicadores->explicacaoDe(self::texto($get('indicador_de_operacao_padrao')))
                        ?? __('Diz onde a operação se considera ocorrida, e por consequência a quem cabe o IBS.')),
            ]);
    }

    /**
     * Quem declara regime de apuracao do Simples e o ME/EPP, e nao o optante em
     * geral: medido contra a API, a biblioteca fiscal grava o `regApTribSN` do
     * ME/EPP no XML e descarta o do MEI.
     */
    private static function declaraApuracaoDoSimples(mixed $estado): bool
    {
        $regime = $estado instanceof RegimeSimplesNacional
            ? $estado
            : RegimeSimplesNacional::tryFrom(is_numeric($estado) ? (int) $estado : 0);

        return $regime === RegimeSimplesNacional::OptanteMicroEmpresa;
    }

    private static function texto(mixed $estado): ?string
    {
        return is_string($estado) && $estado !== '' ? $estado : null;
    }

    private static function certificado(): Section
    {
        return Section::make(__('Certificado A1'))
            ->icon(Heroicon::OutlinedKey)
            ->description(__('O certificado assina a transmissão. Sem ele dá para gerar a DPS, mas não para enviá-la.'))
            ->aside()
            ->visible(fn (?Empresa $record): bool => $record !== null)
            ->schema([
                Text::make(fn (Empresa $record): string => self::descricaoDoCertificado($record))
                    ->color(fn (Empresa $record): string => $record->temCertificado() ? 'success' : 'warning'),

                Text::make(__('O arquivo é enviado pelo botão no topo da página e guardado cifrado. A API fiscal não persiste certificado: ele viaja em cada chamada.'))
                    ->color('gray')
                    ->size('sm'),
            ]);
    }

    private static function ondeASerieChegou(Get $get, ?Empresa $empresa): ?string
    {
        $ultimo = app(NumeracaoDaEmpresa::class)->ultimoNumeroUsado($empresa?->getKey(), $get('serie_dps'));

        if ($ultimo === null) {
            return null;
        }

        return __('A série :serie já chegou ao nº :ultimo.', ['serie' => $get('serie_dps'), 'ultimo' => $ultimo]);
    }

    /**
     * Provedores fora do Padrao Nacional exigem login de webservice alem do
     * certificado. Como ele, a API nao guarda: vai na requisicao e morre com
     * ela. Aqui fica cifrado, do mesmo jeito.
     */
    private static function credenciaisDaPrefeitura(): Section
    {
        return Section::make(__('Credenciais da prefeitura'))
            ->icon(Heroicon::OutlinedLockClosed)
            ->description(__('Só para provedores fora do Padrão Nacional, que pedem login de webservice.'))
            ->aside()
            ->collapsed()
            ->columns(12)
            ->schema([
                TextInput::make('prefeitura_usuario')->label(__('Usuário'))->columnSpan(4),
                TextInput::make('prefeitura_senha')->label(__('Senha'))->password()->revealable()->columnSpan(4),
                TextInput::make('prefeitura_token')->label(__('Token'))->password()->revealable()->columnSpan(4),
            ]);
    }

    private static function descricaoDoCertificado(Empresa $empresa): string
    {
        if (! $empresa->temCertificado()) {
            return __('Nenhum certificado enviado.');
        }

        $validade = $empresa->certificado_valido_ate?->format('d/m/Y') ?? __('validade desconhecida');
        $dias = $empresa->diasAteOCertificadoVencer();

        if ($dias !== null && $dias < 0) {
            return __(':titular — VENCIDO em :validade.', ['titular' => $empresa->certificado_titular, 'validade' => $validade]);
        }

        return __(':titular — válido até :validade.', ['titular' => $empresa->certificado_titular, 'validade' => $validade]);
    }
}
