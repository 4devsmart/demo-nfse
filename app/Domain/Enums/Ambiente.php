<?php

declare(strict_types=1);

namespace App\Domain\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Ambiente de autorizacao. Homologacao nao tem valor fiscal.
 * Espelha o enum `Ambiente` da API fiscal.
 */
enum Ambiente: string implements HasColor, HasLabel
{
    case Homologacao = 'homologacao';
    case Producao = 'producao';

    public function getLabel(): string
    {
        return match ($this) {
            self::Homologacao => __('Homologação'),
            self::Producao => __('Produção'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Homologacao => 'gray',
            self::Producao => 'success',
        };
    }
}
