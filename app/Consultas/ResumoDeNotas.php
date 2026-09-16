<?php

declare(strict_types=1);

namespace App\Consultas;

use App\Domain\Enums\StatusNota;
use App\Domain\ValueObjects\Dinheiro;
use App\Models\Nota;

/**
 * Os numeros do painel. Widget nao consulta banco: consome isto.
 */
final readonly class ResumoDeNotas
{
    public function quantidadePor(StatusNota $status): int
    {
        return Nota::query()->where('status', $status)->count();
    }

    public function total(): int
    {
        return Nota::query()->count();
    }

    public function valorAutorizadoNoMes(): Dinheiro
    {
        $soma = Nota::query()
            ->where('status', StatusNota::Autorizada)
            ->whereBetween('competencia', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('valor_servico');

        return Dinheiro::deReais((float) $soma);
    }

    public function emAberto(): int
    {
        return Nota::query()
            ->whereIn('status', [StatusNota::Rascunho, StatusNota::DpsGerada])
            ->count();
    }

    /**
     * Faturamento autorizado dos ultimos seis meses, do mais antigo para o mais
     * recente, e o que o mini-grafico do painel desenha.
     *
     * @return array<int, float>
     */
    public function faturamentoDosUltimosMeses(int $meses = 6): array
    {
        $inicio = now()->startOfMonth()->subMonths($meses - 1);

        $porMes = Nota::query()
            ->where('status', StatusNota::Autorizada)
            ->where('competencia', '>=', $inicio)
            ->get(['competencia', 'valor_servico'])
            ->groupBy(fn (Nota $nota): string => $nota->competencia->format('Y-m'))
            ->map(fn ($notas): float => (float) $notas->sum('valor_servico'));

        return collect(range(0, $meses - 1))
            ->map(fn (int $passo): float => $porMes->get($inicio->copy()->addMonths($passo)->format('Y-m'), 0.0))
            ->values()
            ->all();
    }

    public function precisamDeAtencao(): int
    {
        return Nota::query()
            ->whereIn('status', [StatusNota::Indeterminada, StatusNota::EmProcessamento, StatusNota::Rejeitada])
            ->count();
    }
}
