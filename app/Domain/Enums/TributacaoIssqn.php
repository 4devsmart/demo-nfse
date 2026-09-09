<?php

declare(strict_types=1);

namespace App\Domain\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * `valores.tribMun.tribISSQN`.
 */
enum TributacaoIssqn: int implements HasLabel
{
    case OperacaoTributavel = 1;
    case ExportacaoDeServico = 2;
    case NaoIncidencia = 3;
    case Imunidade = 4;

    public function getLabel(): string
    {
        return match ($this) {
            self::OperacaoTributavel => __('Operação tributável'),
            self::ExportacaoDeServico => __('Exportação de serviço'),
            self::NaoIncidencia => __('Não incidência'),
            self::Imunidade => __('Imunidade'),
        };
    }

    public function temIssqnDevido(): bool
    {
        return $this === self::OperacaoTributavel;
    }
}
