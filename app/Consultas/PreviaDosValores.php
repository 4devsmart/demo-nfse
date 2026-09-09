<?php

declare(strict_types=1);

namespace App\Consultas;

use App\Domain\Enums\RetencaoIssqn;
use App\Domain\Enums\SituacaoTributariaPisCofins;
use App\Domain\Enums\TributacaoIssqn;
use App\Domain\ValueObjects\Aliquota;
use App\Domain\ValueObjects\Dinheiro;
use App\Fiscal\Dps\BeneficioMunicipal;
use App\Fiscal\Dps\RetencoesFederais;
use App\Fiscal\Dps\ValoresDoServico;
use Throwable;

/**
 * O que o formulario mostra enquanto o usuario digita: base de calculo, ISSQN e
 * liquido. Quem calcula continua sendo ValoresDoServico, aqui so se traduz o
 * estado cru do formulario, que pode estar pela metade, para o tipo do dominio.
 */
final readonly class PreviaDosValores
{
    private function __construct(
        public Dinheiro $baseDeCalculo,
        public Dinheiro $issqn,
        public Dinheiro $liquido,
        public ?RetencoesFederais $retencoesFederais = null,
        public bool $declaraAliquota = true,
    ) {}

    /**
     * @param  array<string, mixed>  $estado
     */
    public static function doFormulario(array $estado): self
    {
        try {
            $valores = self::traduzir($estado);
        } catch (Throwable) {
            // Formulario a meio caminho ainda nao tem numero, e isso nao e erro.
            return new self(Dinheiro::zero(), Dinheiro::zero(), Dinheiro::zero());
        }

        return new self(
            $valores->baseDeCalculo(),
            $valores->issqnDevido(),
            $valores->valorLiquido(),
            $valores->retencoesFederais,
            $valores->declaraAliquota(),
        );
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    private static function traduzir(array $estado): ValoresDoServico
    {
        return ValoresDoServico::cobrados(
            self::dinheiro($estado, 'valor_servico'),
            Aliquota::deQuatroCasas(self::numero($estado, 'aliquota_iss')),
        )
            ->tributadosComo(
                TributacaoIssqn::from(EstadoDoFormulario::inteiro($estado, 'tributacao_issqn', TributacaoIssqn::OperacaoTributavel->value)),
                RetencaoIssqn::from(EstadoDoFormulario::inteiro($estado, 'retencao_issqn', RetencaoIssqn::NaoRetido->value)),
            )
            ->comDeducoes(self::dinheiro($estado, 'deducoes'))
            ->comDescontos(
                self::dinheiro($estado, 'desconto_incondicionado'),
                self::dinheiro($estado, 'desconto_condicionado'),
            )
            ->comBeneficioMunicipal(self::beneficio($estado))
            ->comRetencoesFederais(self::retencoesFederais($estado))
            // Sem isto a previa mostrava um ISSQN que a nota grava como zero:
            // e a mesma pergunta, e quem responde e o cadastro do emitente.
            ->comIssqnNoSimplesNacional(self::apuraPeloSimples($estado));
    }

    /**
     * Se o emitente escolhido apura o ISSQN pela guia unica do Simples. Ver
     * `ValoresDoServico::declaraAliquota()`: e o que a rejeicao E0625 descreve.
     *
     * @param  array<string, mixed>  $estado
     */
    private static function apuraPeloSimples(array $estado): bool
    {
        return app(EmpresasEmitentes::class)
            ->encontrar(EstadoDoFormulario::inteiro($estado, 'empresa_id') ?: null)
            ?->apuraIssqnPeloSimplesNacional() ?? false;
    }

    /**
     * As retencoes federais como o formulario as tem: aliquotas herdadas do
     * emitente, e a lista do que o tomador retem marcada na propria nota.
     *
     * A resposta "nao" tem a ultima palavra, e a guarda existe por isso: campo
     * escondido nao perde o valor, continua guardando o padrao do emitente. Sem
     * ela a previa descontava um IRRF de 1,5% que `TributacaoRespondida` zera na
     * gravacao, e a tela mostrava um liquido que a nota nao ia ter.
     *
     * A base e o valor do servico, e nao a base do ISSQN: deducao e beneficio
     * municipal sao regra do municipio e nao alcancam tributo federal.
     *
     * @param  array<string, mixed>  $estado
     */
    private static function retencoesFederais(array $estado): ?RetencoesFederais
    {
        if (self::respondeuNao($estado, 'tem_retencao_federal')) {
            return null;
        }

        $marcadas = EstadoDoFormulario::lista($estado, 'contribuicoes_retidas');
        $situacao = SituacaoTributariaPisCofins::tryFrom(EstadoDoFormulario::texto($estado, 'cst_pis_cofins'));

        $servico = self::dinheiro($estado, 'valor_servico');

        // As duas bases da lei, como em `Nota::retencoesFederais()`: a prévia
        // erraria o líquido se somasse retenção calculada de outro jeito.
        $retencoes = RetencoesFederais::sobre(
            $servico->subtrair(self::dinheiro($estado, 'desconto_incondicionado')),
            $servico,
        )
            ->tributadasComo($situacao ?? SituacaoTributariaPisCofins::TributavelAliquotaBasica)
            ->comPisCofins(self::aliquota($estado, 'aliquota_pis'), self::aliquota($estado, 'aliquota_cofins'))
            ->comCsll(self::aliquota($estado, 'aliquota_csll'))
            ->comIrrf(self::aliquota($estado, 'aliquota_irrf'))
            ->comPrevidenciaria(self::aliquota($estado, 'aliquota_previdenciaria'))
            ->retidasPeloTomador(
                in_array('pis', $marcadas, true),
                in_array('cofins', $marcadas, true),
                in_array('csll', $marcadas, true),
            );

        return $retencoes->estaZerado() ? null : $retencoes;
    }

    /**
     * Pergunta ausente e atualizacao parcial, e ai nao ha resposta a aplicar.
     * So o "nao" explicito descarta, como em `TributacaoRespondida`.
     *
     * @param  array<string, mixed>  $estado
     */
    private static function respondeuNao(array $estado, string $pergunta): bool
    {
        return array_key_exists($pergunta, $estado) && ! $estado[$pergunta];
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    private static function aliquota(array $estado, string $campo): Aliquota
    {
        return Aliquota::deQuatroCasas(self::numero($estado, $campo));
    }

    /**
     * O beneficio municipal reduz a base, entao a previa tem que enxerga-lo.
     * Senao a tela mostraria um ISSQN que a nota nao vai ter.
     *
     * @param  array<string, mixed>  $estado
     */
    private static function beneficio(array $estado): ?BeneficioMunicipal
    {
        $numero = EstadoDoFormulario::texto($estado, 'numero_beneficio_municipal');

        if ($numero === '') {
            return null;
        }

        return BeneficioMunicipal::concedido(
            $numero,
            Aliquota::deQuatroCasas(EstadoDoFormulario::numero($estado, 'percentual_reducao_base')),
        );
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    private static function dinheiro(array $estado, string $campo): Dinheiro
    {
        return Dinheiro::deReais(self::numero($estado, $campo));
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    private static function numero(array $estado, string $campo): float
    {
        return EstadoDoFormulario::numero($estado, $campo);
    }
}
