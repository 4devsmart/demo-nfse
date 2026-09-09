<?php

declare(strict_types=1);

namespace App\Domain\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * `valores.tribMun.tpSusp`. Quando o recolhimento do ISSQN esta suspenso, a
 * prefeitura precisa saber por que, e o numero do processo e obrigatorio.
 */
enum TipoSuspensaoDeExigibilidade: int implements HasLabel
{
    case DecisaoJudicial = 1;
    case ProcessoAdministrativo = 2;

    public function getLabel(): string
    {
        return match ($this) {
            self::DecisaoJudicial => __('Suspensa por decisão judicial'),
            self::ProcessoAdministrativo => __('Suspensa por processo administrativo'),
        };
    }
}
