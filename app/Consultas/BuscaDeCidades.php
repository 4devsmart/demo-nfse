<?php

declare(strict_types=1);

namespace App\Consultas;

use App\Domain\ValueObjects\CodigoIbge;
use App\Models\Cidade;

/**
 * As telas nao consultam o banco: pedem aqui. Sao 5.571 municipios, entao a
 * selecao e sempre por busca, nunca por lista inteira.
 */
final readonly class BuscaDeCidades
{
    private const LIMITE = 40;

    /**
     * A caixa e baixada antes do `LIKE` pelo mesmo motivo que em
     * `BuscaDeCodigosDeServico::termo()`: o SQLite so dobra caixa em ASCII, e
     * sem isto "SÃO PAULO" nao achava nada enquanto "São Paulo" achava quatro.
     *
     * @return array<int, string>
     */
    public function procurar(string $termo): array
    {
        $busca = mb_strtolower(trim($termo));

        if ($busca === '') {
            return [];
        }

        return Cidade::query()
            ->where(fn ($consulta) => $consulta
                ->where('nome', 'like', "%{$busca}%")
                ->orWhere('codigo_ibge', 'like', "{$busca}%"))
            ->orderBy('nome')
            ->limit(self::LIMITE)
            ->get()
            ->mapWithKeys(fn (Cidade $cidade): array => [$cidade->getKey() => $this->rotulo($cidade)])
            ->all();
    }

    public function rotuloDe(int|string|null $id): ?string
    {
        $cidade = $id === null ? null : Cidade::query()->find($id);

        return $cidade instanceof Cidade ? $this->rotulo($cidade) : null;
    }

    public function idPeloCodigoIbge(CodigoIbge $codigo): ?int
    {
        return Cidade::query()->where('codigo_ibge', (string) $codigo)->value('id');
    }

    public function total(): int
    {
        return Cidade::query()->count();
    }

    private function rotulo(Cidade $cidade): string
    {
        return "{$cidade->nome}/{$cidade->uf} — {$cidade->codigo_ibge}";
    }
}
