<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Consultas\EstadoDaConfiguracao;
use Filament\Widgets\Widget;

/**
 * Some sozinho quando o cadastro esta completo: aviso que fica para sempre vira
 * ruido, e ninguem le.
 */
class PrimeirosPassos extends Widget
{
    protected string $view = 'filament.widgets.primeiros-passos';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -1;

    public static function canView(): bool
    {
        return ! app(EstadoDaConfiguracao::class)->estaCompleta();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['passos' => app(EstadoDaConfiguracao::class)->passos()];
    }
}
