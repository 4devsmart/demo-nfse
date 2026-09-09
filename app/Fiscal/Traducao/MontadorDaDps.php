<?php

declare(strict_types=1);

namespace App\Fiscal\Traducao;

use App\Fiscal\Dps\ConstrutorDps;
use App\Fiscal\Pedidos\PayloadDps;
use App\Models\Nota;

/**
 * Traduz a nota gravada no banco para o corpo que a API fiscal espera. E o unico
 * ponto onde Eloquent encontra o construtor da DPS: dos dois lados dele ninguem
 * precisa conhecer o outro.
 */
final readonly class MontadorDaDps
{
    public function montar(Nota $nota): PayloadDps
    {
        $empresa = $nota->empresa;

        return ConstrutorDps::novo()
            ->noAmbiente($nota->ambiente)
            ->emitidaPor($empresa->comoPrestador())
            ->naCidade($empresa->municipio())
            ->para($nota->cliente->comoTomador())
            ->numerada($nota->serie, (string) $nota->numero)
            ->naCompetencia($nota->competenciaDoServico())
            ->doServico($nota->servicoPrestado())
            ->comValores($nota->valoresDoServico())
            ->comTributacaoIbsCbs($nota->tributacaoIbsCbs())
            ->identificadaPor($nota->referencia)
            ->montar();
    }
}
