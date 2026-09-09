<?php

declare(strict_types=1);

namespace App\Domain\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * `regTrib.regApTribSN`. Diz se o ISSQN do optante do Simples e apurado dentro
 * ou fora da guia unica.
 *
 * So faz sentido para quem e optante, e por isso a coluna e anulavel: nao
 * optante nao tem regime de apuracao do Simples a declarar.
 *
 * O campo nao e enfeite. Sem ele a biblioteca fiscal grava `1` por conta
 * propria, e `1` afirma que o ISSQN vai no Simples: um ME/EPP cujo municipio
 * exige o imposto por fora saia declarando o contrario, sem que o cadastro
 * tivesse onde dizer a verdade.
 */
enum RegimeDeApuracaoDoSimples: int implements HasLabel
{
    case FederaisEMunicipalPeloSimples = 1;
    case FederaisPeloSimplesIssqnPorFora = 2;
    case FederaisEMunicipalPorFora = 3;

    public function getLabel(): string
    {
        return match ($this) {
            self::FederaisEMunicipalPeloSimples => __('Federais e ISSQN pelo Simples Nacional'),
            self::FederaisPeloSimplesIssqnPorFora => __('Federais pelo Simples; ISSQN por fora, pela lei do município'),
            self::FederaisEMunicipalPorFora => __('Federais e ISSQN por fora do Simples'),
        };
    }
}
