<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Consultas\ResumoDeNotas;
use App\Domain\Enums\StatusNota;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * O widget nao consulta o banco: pede os numeros a uma consulta.
 */
class ResumoDoPainel extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected function getHeading(): ?string
    {
        return __('Como está a emissão');
    }

    protected function getStats(): array
    {
        $resumo = app(ResumoDeNotas::class);
        $atencao = $resumo->precisamDeAtencao();

        return [
            Stat::make(__('Faturado no mês'), $resumo->valorAutorizadoNoMes()->formatado())
                ->description(__('Competência corrente, só notas autorizadas'))
                ->descriptionIcon(Heroicon::ArrowTrendingUp)
                ->chart($resumo->faturamentoDosUltimosMeses())
                ->color('primary'),

            Stat::make(__('Autorizadas'), $resumo->quantidadePor(StatusNota::Autorizada))
                ->description(__('Com XML protocolado guardado'))
                ->descriptionIcon(Heroicon::CheckBadge)
                ->color('success'),

            Stat::make(__('Em aberto'), $resumo->emAberto())
                ->description(__('Rascunhos e DPS ainda não transmitidas'))
                ->descriptionIcon(Heroicon::PencilSquare)
                ->color('gray'),

            Stat::make(__('Pedem ação'), $atencao)
                ->description($atencao > 0 ? __('Rejeitadas ou com desfecho indeterminado') : __('Nada pendente'))
                ->descriptionIcon($atencao > 0 ? Heroicon::ExclamationTriangle : Heroicon::Check)
                ->color($atencao > 0 ? 'danger' : 'gray'),
        ];
    }
}
