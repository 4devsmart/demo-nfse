<?php

declare(strict_types=1);

namespace App\Consultas;

use App\Models\Cliente;

final readonly class ClientesTomadores
{
    private const LIMITE = 40;

    /**
     * @return array<int, string>
     */
    public function procurar(string $termo): array
    {
        return Cliente::query()
            ->where(fn ($consulta) => $consulta
                ->where('razao_social', 'like', "%{$termo}%")
                ->orWhere('cpf_cnpj', 'like', "%{$termo}%"))
            ->orderBy('razao_social')
            ->limit(self::LIMITE)
            ->get()
            ->mapWithKeys(fn (Cliente $cliente): array => [$cliente->getKey() => $this->rotulo($cliente)])
            ->all();
    }

    public function encontrar(int|string|null $id): ?Cliente
    {
        return $id === null ? null : Cliente::query()->find($id);
    }

    /**
     * O municipio do tomador, pelo id da cidade: e o que os seletores de
     * municipio guardam.
     */
    public function cidadeDe(int|string|null $id): ?int
    {
        return $this->encontrar($id)?->cidade_id;
    }

    public function rotuloDe(int|string|null $id): ?string
    {
        $cliente = $id === null ? null : Cliente::query()->find($id);

        return $cliente instanceof Cliente ? $this->rotulo($cliente) : null;
    }

    private function rotulo(Cliente $cliente): string
    {
        return "{$cliente->razao_social} — {$cliente->documentoFederal()->formatado()}";
    }
}
