<?php

declare(strict_types=1);

namespace App\Domain\Enums;

use Filament\Support\Contracts\HasLabel;

enum TipoPessoa: string implements HasLabel
{
    case Juridica = 'juridica';
    case Fisica = 'fisica';

    public function getLabel(): string
    {
        return match ($this) {
            self::Juridica => __('Pessoa jurídica'),
            self::Fisica => __('Pessoa física'),
        };
    }
}
