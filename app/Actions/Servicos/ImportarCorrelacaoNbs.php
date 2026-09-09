<?php

declare(strict_types=1);

namespace App\Actions\Servicos;

use App\Models\ItemDaNbs;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carrega a correlacao entre o subitem da LC 116 e os itens da NBS, a partir do
 * arquivo que acompanha o projeto.
 *
 * A gravacao e `upsert` pelo par subitem/NBS, e nao apaga-e-recria: correlacao
 * que sai do anexo nao invalida a nota antiga que a declarou.
 */
final readonly class ImportarCorrelacaoNbs
{
    private const LOTE = 300;

    public function __construct(private FonteDaNbs $fonte) {}

    public function executar(): int
    {
        $correlacoes = $this->fonte->correlacoes();

        if ($correlacoes === []) {
            throw new RuntimeException(__('A fonte não devolveu nenhuma correlação da NBS.'));
        }

        DB::transaction(function () use ($correlacoes): void {
            $agora = now()->toDateTimeString();

            foreach (array_chunk($correlacoes, self::LOTE) as $lote) {
                $carimbado = array_map(
                    static fn (array $linha): array => [...$linha, 'created_at' => $agora, 'updated_at' => $agora],
                    $lote,
                );

                ItemDaNbs::query()->upsert($carimbado, ['item_lista_servico', 'nbs'], ['descricao', 'updated_at']);
            }
        });

        return count($correlacoes);
    }
}
