<?php

declare(strict_types=1);

namespace App\Fiscal\Dps;

use App\Domain\ValueObjects\Aliquota;
use App\Domain\ValueObjects\Dinheiro;

/**
 * `valores.totTrib`. E a Lei da Transparencia (12.741/2012): a nota precisa
 * dizer quanto de tributo ha embutido no preco.
 *
 * O grupo aceita a mesma declaracao de tres formas, e elas se EXCLUEM: valor
 * (`vTotTrib*`), percentual (`pTotTrib*`) e, para quem esta no Simples
 * Nacional, um percentual unico (`pTotTribSN`). E escolha, nao soma, e por isso
 * ela e feita aqui, e nao pelo acaso de quais campos vieram preenchidos.
 *
 * Fora do Simples, este projeto declara em VALOR: a lei fala em "valor
 * aproximado", e e o valor que o DANFSe imprime. Dentro do Simples, declara o
 * percentual unico, porque nao ha o que separar entre os tres entes: o DAS
 * reune IRPJ, CSLL, PIS, COFINS, CPP e o proprio ISS numa guia so.
 *
 * O `pTotTribSN` so chega ao XML quando o prestador vai declarado como optante
 * (`regTrib.opSimpNac` 2 ou 3): a wrapper ignora o campo quando quem emite e
 * nao optante, e escreve `<vTotTrib>` no lugar. E o acoplamento certo, e o
 * mesmo que `Nota::declaraPeloSimplesNacional()` faz deste lado, entao os dois
 * concordam sem combinar.
 */
final readonly class TotaisAproximados
{
    public function __construct(
        public Dinheiro $federais,
        public Dinheiro $estaduais,
        public Dinheiro $municipais,
        public ?Aliquota $percentualDoSimples = null,
    ) {}

    /**
     * A aliquota efetiva do Simples na competencia da nota. Nao ha os tres
     * valores neste caminho, e nao e omissao: separar a guia unica em federal,
     * estadual e municipal seria inventar um rateio que a lei do Simples nao
     * faz.
     */
    public static function doSimplesNacional(Aliquota $percentual): self
    {
        return new self(Dinheiro::zero(), Dinheiro::zero(), Dinheiro::zero(), $percentual);
    }

    public function estaZerado(): bool
    {
        if ($this->percentualDoSimples !== null) {
            return $this->percentualDoSimples->ehZero();
        }

        return $this->federais->ehZero() && $this->estaduais->ehZero() && $this->municipais->ehZero();
    }

    /**
     * @return array<string, float>
     */
    public function paraApi(): array
    {
        if ($this->percentualDoSimples !== null) {
            return ['pTotTribSN' => $this->percentualDoSimples->percentual];
        }

        return [
            'vTotTribFed' => $this->federais->emReais(),
            'vTotTribEst' => $this->estaduais->emReais(),
            'vTotTribMun' => $this->municipais->emReais(),
        ];
    }
}
