<?php

declare(strict_types=1);

namespace App\Consultas;

use App\Models\IndicadorDeOperacao;

/**
 * A tabela `cIndOp` como a tela precisa dela. Sao 36 codigos, entao cabe uma
 * lista unica, agrupada pelo tipo de operacao.
 */
final readonly class BuscaDeIndicadores
{
    /**
     * @return array<string, string>
     */
    public function paraSelecao(): array
    {
        return IndicadorDeOperacao::query()
            ->orderBy('codigo')
            ->get()
            ->mapWithKeys(fn (IndicadorDeOperacao $indicador): array => [
                $indicador->codigo => $indicador->rotulo(),
            ])
            ->all();
    }

    public function rotuloDe(?string $codigo): ?string
    {
        if (blank($codigo)) {
            return null;
        }

        $indicador = IndicadorDeOperacao::query()->where('codigo', $codigo)->first();

        return $indicador instanceof IndicadorDeOperacao ? $indicador->rotulo() : null;
    }

    /**
     * O que a escolha significa, para aparecer embaixo do seletor: e o local do
     * fornecimento que decide a quem cabe o IBS.
     */
    public function explicacaoDe(?string $codigo): ?string
    {
        if (blank($codigo)) {
            return null;
        }

        $indicador = IndicadorDeOperacao::query()->where('codigo', $codigo)->first();

        if (! $indicador instanceof IndicadorDeOperacao) {
            return null;
        }

        return "{$indicador->caracteristica} · {$indicador->local_do_fornecimento} ({$indicador->dispositivo_legal})";
    }

    public function total(): int
    {
        return IndicadorDeOperacao::query()->count();
    }
}
