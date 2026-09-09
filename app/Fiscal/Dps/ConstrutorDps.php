<?php

declare(strict_types=1);

namespace App\Fiscal\Dps;

use App\Domain\Enums\Ambiente;
use App\Domain\ValueObjects\CodigoIbge;
use App\Domain\ValueObjects\Competencia;
use App\Fiscal\Pedidos\PayloadDps;
use LogicException;

/**
 * Montagem fluida da DPS.
 *
 *   ConstrutorDps::novo()
 *       ->noAmbiente($ambiente)
 *       ->emitidaPor($prestador)->naCidade($codigoIbge)
 *       ->para($tomador)
 *       ->numerada($serie, $numero)->naCompetencia($competencia)
 *       ->doServico($servico)->comValores($valores)
 *       ->comTributacaoIbsCbs($ibsCbs)
 *       ->identificadaPor($referencia)
 *       ->montar();
 *
 * Cada passo devolve uma instancia nova: o construtor nunca e compartilhado por
 * engano entre duas notas.
 */
final readonly class ConstrutorDps
{
    private const VERSAO_DO_APLICATIVO = 'nfse-demo';

    private function __construct(
        private ?Ambiente $ambiente = null,
        private ?Pessoa $prestador = null,
        private ?Pessoa $tomador = null,
        private ?CodigoIbge $municipioEmissor = null,
        private ?ServicoPrestado $servico = null,
        private ?ValoresDoServico $valores = null,
        private ?TributacaoIbsCbs $ibsCbs = null,
        private ?Competencia $competencia = null,
        private string $serie = '',
        private string $numero = '',
        private string $referencia = '',
    ) {}

    public static function novo(): self
    {
        return new self;
    }

    public function noAmbiente(Ambiente $ambiente): self
    {
        return $this->com(ambiente: $ambiente);
    }

    public function emitidaPor(Pessoa $prestador): self
    {
        return $this->com(prestador: $prestador);
    }

    public function naCidade(CodigoIbge $municipioEmissor): self
    {
        return $this->com(municipioEmissor: $municipioEmissor);
    }

    public function para(Pessoa $tomador): self
    {
        return $this->com(tomador: $tomador);
    }

    public function numerada(string $serie, string $numero): self
    {
        return $this->com(serie: $serie, numero: $numero);
    }

    public function naCompetencia(Competencia $competencia): self
    {
        return $this->com(competencia: $competencia);
    }

    public function doServico(ServicoPrestado $servico): self
    {
        return $this->com(servico: $servico);
    }

    public function comValores(ValoresDoServico $valores): self
    {
        return $this->com(valores: $valores);
    }

    /**
     * A classificacao de IBS/CBS. Opcional enquanto o grupo nao e obrigatorio
     * no Padrao Nacional: sem ela a DPS sai como saia antes da Reforma.
     */
    public function comTributacaoIbsCbs(?TributacaoIbsCbs $ibsCbs): self
    {
        return $this->com(ibsCbs: $ibsCbs);
    }

    public function identificadaPor(string $referencia): self
    {
        return $this->com(referencia: $referencia);
    }

    public function montar(): PayloadDps
    {
        $this->exigirPreenchimento();
        $this->exigirValoresCoerentes();

        // A análise estática não enxerga a garantia dada por
        // `exigirPreenchimento()`. Os `assert` repetem para ela o que a linha
        // acima já provou.
        assert($this->ambiente !== null);
        assert($this->prestador !== null);
        assert($this->tomador !== null);
        assert($this->municipioEmissor !== null);
        assert($this->servico !== null);
        assert($this->valores !== null);
        assert($this->competencia !== null);

        return new PayloadDps(self::semVazios([
            'ambiente' => $this->ambiente->value,
            'referencia' => $this->referencia,
            'infDPS' => [
                'serie' => $this->serie,
                'nDPS' => $this->numero,
                'dCompet' => $this->competencia->emIso(),
                'tpEmit' => 1,
                'verAplic' => self::VERSAO_DO_APLICATIVO,
                'cLocEmi' => (string) $this->municipioEmissor,
                'prest' => $this->prestador->paraApi(),
                'toma' => $this->tomador->paraApi(),
                'serv' => $this->servico->paraApi(),
                'valores' => $this->valores->paraApi(),
                'ibscbs' => $this->ibsCbs?->paraApi(),
            ],
        ]));
    }

    /**
     * Um só lugar reconstrói o construtor. Cada `com*` acima passa apenas o
     * campo que muda; o resto vem do estado atual.
     */
    private function com(
        ?Ambiente $ambiente = null,
        ?Pessoa $prestador = null,
        ?Pessoa $tomador = null,
        ?CodigoIbge $municipioEmissor = null,
        ?ServicoPrestado $servico = null,
        ?ValoresDoServico $valores = null,
        ?TributacaoIbsCbs $ibsCbs = null,
        ?Competencia $competencia = null,
        ?string $serie = null,
        ?string $numero = null,
        ?string $referencia = null,
    ): self {
        return new self(
            $ambiente ?? $this->ambiente,
            $prestador ?? $this->prestador,
            $tomador ?? $this->tomador,
            $municipioEmissor ?? $this->municipioEmissor,
            $servico ?? $this->servico,
            $valores ?? $this->valores,
            $ibsCbs ?? $this->ibsCbs,
            $competencia ?? $this->competencia,
            $serie ?? $this->serie,
            $numero ?? $this->numero,
            $referencia ?? $this->referencia,
        );
    }

    private function exigirPreenchimento(): void
    {
        $faltando = array_keys(array_filter([
            __('ambiente') => $this->ambiente === null,
            __('prestador') => $this->prestador === null,
            __('tomador') => $this->tomador === null,
            __('municipio emissor') => $this->municipioEmissor === null,
            __('serviço') => $this->servico === null,
            __('valores') => $this->valores === null,
            __('competência') => $this->competencia === null,
            __('número') => $this->numero === '',
        ]));

        if ($faltando === []) {
            return;
        }

        throw new LogicException(__('DPS incompleta, falta: :campos.', ['campos' => implode(', ', $faltando)]));
    }

    /**
     * Preenchida nao e o mesmo que coerente. Uma DPS com abatimentos maiores
     * que o servico tem todos os campos e mesmo assim descreve um imposto
     * negativo, e como os valores viajam separados, quem faria a conta e o
     * provedor, ja com o documento na mao.
     */
    private function exigirValoresCoerentes(): void
    {
        assert($this->valores !== null);

        if (! $this->valores->abatimentosPassamDoServico()) {
            return;
        }

        throw new LogicException(__(
            'Deduções e desconto incondicionado somam mais que o valor do serviço: '
            .'a base de cálculo ficaria negativa.'
        ));
    }

    /**
     * A API recusa campo desconhecido com 400, mas campo vazio vira XML com dado
     * faltando. Podar antes de enviar e mais barato que descobrir depois.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private static function semVazios(array $dados): array
    {
        $limpos = [];

        foreach ($dados as $chave => $valor) {
            $podado = is_array($valor) ? self::semVazios($valor) : $valor;

            if ($podado === null || $podado === '' || $podado === []) {
                continue;
            }

            $limpos[$chave] = $podado;
        }

        return $limpos;
    }
}
