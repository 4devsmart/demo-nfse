<?php

declare(strict_types=1);

namespace App\Domain\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * `valores.tribMun.tpRetISSQN`. Quem recolhe o ISSQN.
 */
enum RetencaoIssqn: int implements HasLabel
{
    case NaoRetido = 1;
    case RetidoPeloTomador = 2;
    case RetidoPeloIntermediario = 3;

    public function getLabel(): string
    {
        return match ($this) {
            self::NaoRetido => __('Não retido'),
            self::RetidoPeloTomador => __('Retido pelo tomador'),
            self::RetidoPeloIntermediario => __('Retido pelo intermediário'),
        };
    }
}
