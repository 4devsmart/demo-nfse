<?php

declare(strict_types=1);

namespace App\Consultas;

use App\Models\Empresa;

final readonly class EmpresasEmitentes
{
    /**
     * @return array<int, string>
     */
    public function paraSelecao(): array
    {
        return Empresa::query()
            ->orderBy('razao_social')
            ->get()
            ->mapWithKeys(fn (Empresa $empresa): array => [
                $empresa->getKey() => "{$empresa->razao_social} — {$empresa->documentoFederal()->formatado()}",
            ])
            ->all();
    }

    public function padrao(): ?Empresa
    {
        return Empresa::query()->oldest('id')->first();
    }

    public function encontrar(int|string|null $id): ?Empresa
    {
        return $id === null ? null : Empresa::query()->find($id);
    }
}
