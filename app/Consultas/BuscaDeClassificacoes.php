<?php

declare(strict_types=1);

namespace App\Consultas;

use App\Models\ClassificacaoTributaria;

/**
 * A tabela `cClassTrib` como a tela precisa dela. Sao 71 codigos validos para
 * NFS-e sob 12 CST, entao a escolha e em dois passos, primeiro o CST e depois a
 * classificacao, e nao uma lista unica de 71 linhas parecidas.
 *
 * So classificacao vigente e oferecida. A revogada continua na tabela para
 * explicar a nota que ja a declarou, e por isso `rotuloDe()` nao filtra por
 * vigencia: ele responde sobre o que a nota tem, nao sobre o que dava para
 * escolher hoje.
 */
final readonly class BuscaDeClassificacoes
{
    /**
     * @return array<string, string>
     */
    public function situacoesTributarias(): array
    {
        return ClassificacaoTributaria::query()
            ->vigente()
            ->orderBy('cst')
            ->get(['cst', 'nome_cst'])
            ->unique('cst')
            ->mapWithKeys(fn (ClassificacaoTributaria $classificacao): array => [
                $classificacao->cst => "{$classificacao->cst} · {$classificacao->nome_cst}",
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function paraSelecao(?string $cst): array
    {
        if (blank($cst)) {
            return [];
        }

        return ClassificacaoTributaria::query()
            ->vigente()
            ->where('cst', $cst)
            ->orderBy('codigo')
            ->get()
            ->mapWithKeys(fn (ClassificacaoTributaria $classificacao): array => [
                $classificacao->codigo => $classificacao->rotulo(),
            ])
            ->all();
    }

    public function rotuloDe(?string $codigo): ?string
    {
        if (blank($codigo)) {
            return null;
        }

        $classificacao = ClassificacaoTributaria::query()->where('codigo', $codigo)->first();

        return $classificacao instanceof ClassificacaoTributaria ? $classificacao->rotulo() : null;
    }

    /**
     * Das 71 classificacoes validas para NFS-e, uma permite credito presumido.
     * Perguntar o codigo nas outras setenta e oferecer um campo que a escolha
     * ja tornou impossivel.
     */
    public function permiteCreditoPresumido(?string $codigo): bool
    {
        if (blank($codigo)) {
            return false;
        }

        return ClassificacaoTributaria::query()
            ->where('codigo', $codigo)
            ->where('permite_credito_presumido', true)
            ->exists();
    }

    public function total(): int
    {
        return ClassificacaoTributaria::query()->count();
    }
}
