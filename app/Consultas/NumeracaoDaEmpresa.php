<?php

declare(strict_types=1);

namespace App\Consultas;

use App\Models\Nota;

/**
 * Onde a sequencia de DPS de um emitente ja chegou. Serve para impedir que o
 * contador seja editado para tras no cadastro: o numero repetido bateria no
 * indice unico so na hora de emitir, e o erro apareceria longe da causa.
 */
final readonly class NumeracaoDaEmpresa
{
    public function ultimoNumeroUsado(int|string|null $empresaId, ?string $serie): ?int
    {
        if ($empresaId === null || $serie === null) {
            return null;
        }

        $ultimo = Nota::query()
            ->where('empresa_id', $empresaId)
            ->where('serie', $serie)
            ->max('numero');

        return $ultimo === null ? null : (int) $ultimo;
    }
}
