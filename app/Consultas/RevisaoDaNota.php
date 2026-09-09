<?php

declare(strict_types=1);

namespace App\Consultas;

use App\Domain\Enums\RetencaoIssqn;
use App\Domain\Enums\SituacaoTributariaPisCofins;
use App\Domain\Enums\TipoSuspensaoDeExigibilidade;
use App\Domain\Enums\TributacaoIssqn;
use App\Domain\ValueObjects\Competencia;
use App\Domain\ValueObjects\Dinheiro;

/**
 * O resumo que aparece antes de emitir, montado a partir do estado do
 * formulario, na criacao a nota ainda nao existe no banco.
 *
 * Espelha a etapa "Emitir NFS-e" do Emissor Nacional: conferir o que foi
 * preenchido, com o calculo do imposto a vista, antes de qualquer coisa sair.
 */
final readonly class RevisaoDaNota
{
    public function __construct(
        private EmpresasEmitentes $empresas,
        private ClientesTomadores $clientes,
        private BuscaDeCidades $cidades,
        private BuscaDeClassificacoes $classificacoes,
        private BuscaDeIndicadores $indicadores,
        private BuscaDeCodigosDeServico $servicos,
    ) {}

    /**
     * Cada parte vira um bloco com titulo, nome em destaque e o resto como
     * apoio, em vez de dez pares rotulo/valor com o mesmo peso.
     *
     * @param  array<string, mixed>  $estado
     * @return list<array{rotulo: string, valor: string, apoio: string}>
     */
    public function pessoas(array $estado): array
    {
        $empresa = $this->empresas->encontrar($estado['empresa_id'] ?? null);
        $tomador = $this->clientes->encontrar($estado['cliente_id'] ?? null);

        return [
            [
                'rotulo' => __('Prestador'),
                'valor' => $empresa === null ? '—' : $empresa->razao_social,
                // O `array_filter` existe pela inscricao municipal, que e
                // opcional: sem ele o apoio sairia com dois separadores juntos.
                'apoio' => $empresa === null ? '' : implode(' · ', array_filter([
                    $empresa->documentoFederal()->formatado(),
                    $empresa->inscricao_municipal === null ? '' : __('IM :inscricao', ['inscricao' => $empresa->inscricao_municipal]),
                    $empresa->cidade->nomeComUf(),
                ])),
            ],
            [
                'rotulo' => __('Tomador'),
                'valor' => $tomador === null ? '—' : $tomador->razao_social,
                // Sem `array_filter`, ao contrario do prestador: nenhuma das
                // duas partes e opcional, entao nao ha o que filtrar.
                'apoio' => $tomador === null ? '' : implode(' · ', [
                    $tomador->documentoFederal()->formatado(),
                    $tomador->cidade->nomeComUf(),
                ]),
            ],
        ];
    }

    /**
     * Os dados curtos vao numa grade; a discriminacao e texto corrido e fica
     * separada, porque ler descricao em coluna estreita e ruim.
     *
     * @param  array<string, mixed>  $estado
     * @return array{campos: array<string, string>, discriminacao: string}
     */
    public function servico(array $estado): array
    {
        return [
            'campos' => array_filter([
                __('Competência') => $this->competencia($estado),
                __('Município da prestação') => $this->municipioDaPrestacao($estado),
                __('Código do serviço') => $this->rotuloDoCodigoDoServico($estado),
                __('CNAE') => EstadoDoFormulario::texto($estado, 'cnae'),
                __('Item da lista (ABRASF)') => EstadoDoFormulario::texto($estado, 'item_lista_servico'),
            ], static fn (string $valor): bool => $valor !== ''),
            'discriminacao' => EstadoDoFormulario::texto($estado, 'descricao_servico'),
        ];
    }

    /**
     * O codigo com a descricao curta, como os vizinhos do cartao. Codigo que a
     * tabela nao conhece cai no texto cru: e o que esta gravado, e escondê-lo
     * seria pior que mostrá-lo sem explicacao.
     *
     * @param  array<string, mixed>  $estado
     */
    private function rotuloDoCodigoDoServico(array $estado): string
    {
        $codigo = EstadoDoFormulario::texto($estado, 'codigo_servico');

        return $this->servicos->rotuloCurtoDeCodigo($codigo) ?? $codigo;
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    private function municipioDaPrestacao(array $estado): string
    {
        $rotulo = $this->cidades->rotuloDe($estado['cidade_prestacao_id'] ?? null);

        if ($rotulo === null) {
            return '—';
        }

        // O rotulo da busca traz o codigo IBGE depois de um travessao, que na
        // revisao so ocupa espaco. O corte vem depois da guarda acima porque o
        // travessao e o mesmo caractere que marca "nao escolhido".
        return trim(explode('—', $rotulo)[0]);
    }

    /**
     * So o que foi escolhido. "Não" repetido quatro vezes nao informa nada, e
     * a ausencia da linha ja diz que nao ha suspensao nem beneficio.
     *
     * @param  array<string, mixed>  $estado
     * @return array<string, string>
     */
    public function tributacao(array $estado): array
    {
        $suspensao = TipoSuspensaoDeExigibilidade::tryFrom(EstadoDoFormulario::inteiro($estado, 'tipo_suspensao'));
        $beneficio = EstadoDoFormulario::texto($estado, 'numero_beneficio_municipal');

        return array_filter([
            __('Tributação do ISSQN') => $this->rotuloDaTributacao($estado),
            __('Retenção') => $this->rotuloDaRetencao($estado),
            __('CST do PIS/COFINS') => $this->rotuloDoCstFederal($estado),
            __('Retenção federal') => $this->rotuloDaRetencaoFederal($estado),
            __('IBS/CBS') => $this->rotuloDaClassificacao($estado),
            __('Indicador da operação') => $this->rotuloDoIndicador($estado),
            __('Exigibilidade') => $suspensao === null
                ? ''
                : __(':tipo · processo :processo', ['tipo' => $suspensao->getLabel(), 'processo' => EstadoDoFormulario::texto($estado, 'numero_processo_suspensao')]),
            __('Benefício municipal') => $beneficio === ''
                ? ''
                : __(':numero · reduz :percentual% da base', ['numero' => $beneficio, 'percentual' => number_format(EstadoDoFormulario::numero($estado, 'percentual_reducao_base'), 2, ',', '.')]),
        ], static fn (string $valor): bool => $valor !== '');
    }

    /**
     * O bloco de calculo: e o que o Emissor Nacional chama de "prévia dos
     * valores da NFS-e". Uma conta, na ordem em que ela e feita. Abatimentos
     * zerados nao entram: linha "R$ 0,00" so afasta o olho do que importa.
     *
     * @param  array<string, mixed>  $estado
     * @return list<array{rotulo: string, valor: string, papel: string}>
     */
    public function previa(array $estado): array
    {
        $previa = PreviaDosValores::doFormulario($estado);
        $aliquota = EstadoDoFormulario::numero($estado, 'aliquota_iss');

        // Só o que sai da base fica acima dela. O desconto condicionado não
        // muda o imposto, e somado aqui deixaria a coluna sem fechar com a
        // base mostrada na linha seguinte.
        $abatimentosDaBase = [
            __('Deduções') => EstadoDoFormulario::numero($estado, 'deducoes'),
            __('Desconto incondicionado') => EstadoDoFormulario::numero($estado, 'desconto_incondicionado'),
        ];

        $linhas = [$this->linha(__('Valor do serviço'), $this->emReais(EstadoDoFormulario::numero($estado, 'valor_servico')))];

        foreach ($abatimentosDaBase as $rotulo => $valor) {
            if ($valor > 0) {
                $linhas[] = $this->linha("− {$rotulo}", $this->emReais($valor));
            }
        }

        $linhas[] = $this->linha(__('Base de cálculo'), $previa->baseDeCalculo->formatado(), 'destaque');
        $linhas[] = $this->linha(__('Alíquota aplicada'), number_format($aliquota, 2, ',', '.').'%');
        $linhas[] = $this->linha(__('ISSQN'), $previa->issqn->formatado(), 'destaque');

        // Depois do imposto, porque é depois dele que o condicionado age: ele
        // sai do que o tomador paga, não do que o fisco cobra.
        $condicionado = EstadoDoFormulario::numero($estado, 'desconto_condicionado');

        if ($condicionado > 0) {
            $linhas[] = $this->linha('− '.__('Desconto condicionado'), $this->emReais($condicionado));
        }

        // O ISSQN só sai do líquido quando é retido: sem retenção quem recolhe
        // é o prestador, e ele recebe o valor cheio. A linha existe para que a
        // subtração feche à vista de quem confere.
        $retido = $this->issqnRetido($estado);

        if ($retido !== null) {
            $linhas[] = $this->linha($retido, $previa->issqn->formatado());
        }

        // As retenções federais aparecem uma a uma, e não como o total que a
        // DPS envia. Na DPS, PIS, COFINS e CSLL retidos vão somados num campo
        // só, `vRetCSLL`, por exigência da NT 007; na tela isso seria uma linha
        // que ninguém confere. Quem mostra a composição do jeito da NT é o
        // bloco de tributação, pelo rótulo do `tpRetPisCofins`.
        foreach ($this->retencoesFederais($previa) as $rotulo => $valor) {
            $linhas[] = $this->linha("− {$rotulo}", $valor->formatado());
        }

        $linhas[] = $this->linha(__('Valor líquido da NFS-e'), $previa->liquido->formatado(), 'total');

        return $linhas;
    }

    /**
     * As retenções federais que saem do líquido, já sem as zeradas.
     *
     * @return array<string, Dinheiro>
     */
    private function retencoesFederais(PreviaDosValores $previa): array
    {
        $retencoes = $previa->retencoesFederais;

        if ($retencoes === null) {
            return [];
        }

        return array_filter([
            __('PIS retido') => $retencoes->pisRetido(),
            __('COFINS retido') => $retencoes->cofinsRetido(),
            __('CSLL retida') => $retencoes->csllRetida(),
            __('IRRF retido') => $retencoes->irrfRetido(),
            __('Previdenciária retida') => $retencoes->previdenciariaRetida(),
        ], static fn (Dinheiro $valor): bool => ! $valor->ehZero());
    }

    /**
     * O código do `tpRetPisCofins` por extenso. É ele que diz quais das três
     * contribuições sociais compõem o `vRetCSLL` que a DPS envia somado.
     *
     * @param  array<string, mixed>  $estado
     */
    private function rotuloDaRetencaoFederal(array $estado): string
    {
        $retencoes = PreviaDosValores::doFormulario($estado)->retencoesFederais;

        return $retencoes === null ? '' : $retencoes->tipoDeRetencao()->getLabel();
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    private function rotuloDoCstFederal(array $estado): string
    {
        $situacao = SituacaoTributariaPisCofins::tryFrom(EstadoDoFormulario::texto($estado, 'cst_pis_cofins'));

        if ($situacao === null || PreviaDosValores::doFormulario($estado)->retencoesFederais === null) {
            return '';
        }

        return "{$situacao->value} · {$situacao->getLabel()}";
    }

    /**
     * O par CST + `cClassTrib`. A DPS só classifica: não há valor de IBS nem de
     * CBS a conferir aqui, porque quem os calcula é a Sefin Nacional.
     *
     * @param  array<string, mixed>  $estado
     */
    private function rotuloDaClassificacao(array $estado): string
    {
        $classificacao = EstadoDoFormulario::texto($estado, 'classificacao_tributaria');

        // Mesma guarda da prévia: campo escondido guarda o padrão do emitente,
        // e sem ela a revisão mostraria uma classificação que a nota não terá.
        if ($classificacao === '' || $this->respondeuNao($estado, 'tem_ibs_cbs')) {
            return '';
        }

        return __('CST :cst · :rotulo', [
            'cst' => EstadoDoFormulario::texto($estado, 'cst_ibs_cbs'),
            'rotulo' => $this->classificacoes->rotuloDe($classificacao) ?? $classificacao,
        ]);
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    private function rotuloDoIndicador(array $estado): string
    {
        $indicador = EstadoDoFormulario::texto($estado, 'indicador_de_operacao');

        if ($indicador === '' || $this->respondeuNao($estado, 'tem_ibs_cbs')) {
            return '';
        }

        return $this->indicadores->rotuloDe($indicador) ?? $indicador;
    }

    /**
     * @return array{rotulo: string, valor: string, papel: string}
     */
    private function linha(string $rotulo, string $valor, string $papel = ''): array
    {
        return ['rotulo' => $rotulo, 'valor' => $valor, 'papel' => $papel];
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    private function competencia(array $estado): string
    {
        $data = EstadoDoFormulario::texto($estado, 'competencia');

        return $data === '' ? '—' : Competencia::noMesDe($data)->rotulo();
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    private function rotuloDaTributacao(array $estado): string
    {
        $tributacao = TributacaoIssqn::tryFrom(EstadoDoFormulario::inteiro($estado, 'tributacao_issqn'));

        return $tributacao?->getLabel() ?? '—';
    }

    /**
     * O rótulo da linha de retenção, ou `null` quando não há retenção, que é
     * o caso comum. O padrão é o mesmo de `PreviaDosValores`, senão a linha e o
     * número que ela explica poderiam discordar.
     *
     * @param  array<string, mixed>  $estado
     */
    private function issqnRetido(array $estado): ?string
    {
        $retencao = RetencaoIssqn::tryFrom(
            EstadoDoFormulario::inteiro($estado, 'retencao_issqn', RetencaoIssqn::NaoRetido->value)
        );

        if ($retencao === null || $retencao === RetencaoIssqn::NaoRetido) {
            return null;
        }

        return '− '.__('ISSQN :retencao', ['retencao' => mb_strtolower($retencao->getLabel())]);
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    private function rotuloDaRetencao(array $estado): string
    {
        $retencao = RetencaoIssqn::tryFrom(EstadoDoFormulario::inteiro($estado, 'retencao_issqn'));

        return $retencao?->getLabel() ?? '—';
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    private function respondeuNao(array $estado, string $pergunta): bool
    {
        return array_key_exists($pergunta, $estado) && ! $estado[$pergunta];
    }

    private function emReais(float $valor): string
    {
        return 'R$ '.number_format($valor, 2, ',', '.');
    }
}
