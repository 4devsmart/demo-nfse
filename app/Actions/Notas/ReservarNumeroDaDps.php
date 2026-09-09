<?php

declare(strict_types=1);

namespace App\Actions\Notas;

use App\Models\Empresa;
use Illuminate\Support\Facades\DB;

/**
 * A API fiscal nao controla numeracao nem impede duplicidade, isso e do sistema
 * emissor, e esta e a unica porta por onde um numero de DPS sai.
 *
 * O incremento acontece no banco, em um comando so (`SET col = col + 1`), e nao
 * lendo para depois escrever. A diferenca importa: ler-e-depois-escrever deixa
 * duas requisicoes simultaneas lerem o mesmo valor, e `lockForUpdate()` nao
 * resolve isso no SQLite, que simplesmente ignora `SELECT ... FOR UPDATE`.
 *
 * O indice unico em (empresa_id, serie, numero) e a rede embaixo: se algo aqui
 * falhar, a duplicidade vira erro em vez de virar documento fiscal repetido.
 */
final readonly class ReservarNumeroDaDps
{
    public function executar(Empresa $empresa): int
    {
        return DB::transaction(function () use ($empresa): int {
            $consulta = Empresa::query()->whereKey($empresa->getKey());

            // O incremento toma a trava de escrita antes de qualquer leitura.
            $consulta->clone()->increment('proximo_numero_dps');

            $proximo = (int) $consulta->clone()->value('proximo_numero_dps');
            $empresa->setAttribute('proximo_numero_dps', $proximo);

            return $proximo - 1;
        });
    }
}
