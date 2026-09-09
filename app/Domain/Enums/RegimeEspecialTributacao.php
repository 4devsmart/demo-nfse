<?php

declare(strict_types=1);

namespace App\Domain\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * `regTrib.regEspTrib` do prestador.
 */
enum RegimeEspecialTributacao: int implements HasLabel
{
    case Nenhum = 0;
    case AtoCooperado = 1;
    case Estimativa = 2;
    case MicroempresaMunicipal = 3;
    case NotarioOuRegistrador = 4;
    case ProfissionalAutonomo = 5;
    case SociedadeDeProfissionais = 6;
    case Outros = 9;

    public function getLabel(): string
    {
        return match ($this) {
            self::Nenhum => __('Nenhum'),
            self::AtoCooperado => __('Ato cooperado'),
            self::Estimativa => __('Estimativa'),
            self::MicroempresaMunicipal => __('Microempresa municipal'),
            self::NotarioOuRegistrador => __('Notário ou registrador'),
            self::ProfissionalAutonomo => __('Profissional autônomo'),
            self::SociedadeDeProfissionais => __('Sociedade de profissionais'),
            self::Outros => __('Outros'),
        };
    }
}
