<?php

declare(strict_types=1);

namespace App\Actions\Servicos;

use App\Models\ItemDaListaDeServicos;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carrega a lista de servicos anexa a LC 116/2003 a partir do arquivo que
 * acompanha o projeto.
 *
 * A gravacao e `upsert` por `codigo`, e nao apaga-e-recria, pelo mesmo motivo
 * das classificacoes: subitem revogado sai da lista, e apagar a linha deixaria
 * sem explicacao a nota antiga que o declarou.
 *
 * Um subitem nao tem par na tabela nacional: o 11.05, incluido pela LC 183/2021,
 * ainda nao ganhou desdobramento no `cTribNac`. Ele entra aqui porque a lei o
 * lista, e um provedor ABRASF pode pedi-lo; quem emite pelo Padrao Nacional nao
 * o encontra no outro seletor, e e assim mesmo.
 */
final readonly class ImportarListaDeServicos
{
    public function __construct(private FonteDaListaDeServicos $fonte) {}

    public function executar(): int
    {
        $itens = $this->fonte->itens();

        if ($itens === []) {
            throw new RuntimeException(__('A fonte não devolveu nenhum item da lista de serviços.'));
        }

        DB::transaction(function () use ($itens): void {
            $agora = now()->toDateTimeString();

            $lote = array_map(
                static fn (array $item): array => [...$item, 'created_at' => $agora, 'updated_at' => $agora],
                $itens,
            );

            ItemDaListaDeServicos::query()->upsert($lote, ['codigo'], ['descricao', 'updated_at']);
        });

        return count($itens);
    }
}
