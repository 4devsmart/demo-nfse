<?php

declare(strict_types=1);

namespace App\Consultas;

use App\Models\Nota;
use Illuminate\Database\Eloquent\Builder;

/**
 * As ultimas notas do painel.
 *
 * Devolve a consulta, e nao o resultado, porque a tabela do Filament ordena e
 * pagina por conta dela: entregar colecao pronta traria esse trabalho para ca.
 * O que fica aqui e o que "recente" significa e o que precisa vir junto para a
 * tabela nao cair em N+1, e nada disso e assunto do widget.
 */
final readonly class NotasRecentes
{
    public const QUANTIDADE_NO_PAINEL = 10;

    /**
     * @return Builder<Nota>
     */
    public function consulta(int $quantidade = self::QUANTIDADE_NO_PAINEL): Builder
    {
        return Nota::query()
            ->with(['empresa', 'cliente'])
            ->latest('id')
            ->limit($quantidade);
    }
}
