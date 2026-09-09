<?php

declare(strict_types=1);

namespace App\Domain\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * `regTrib.opSimpNac` do prestador. Obrigatorio no Padrao Nacional: sem ele a
 * biblioteca fiscal nao monta o XML.
 */
enum RegimeSimplesNacional: int implements HasLabel
{
    case NaoOptante = 1;
    case OptanteMei = 2;
    case OptanteMicroEmpresa = 3;

    /**
     * MEI e ME/EPP recolhem por guia unica; o nao optante apura tributo a
     * tributo. A distincao muda o que a nota declara na Lei da Transparencia.
     */
    public function ehOptante(): bool
    {
        return $this !== self::NaoOptante;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::NaoOptante => __('Não optante'),
            self::OptanteMei => __('Optante — MEI'),
            self::OptanteMicroEmpresa => __('Optante — ME/EPP'),
        };
    }
}
