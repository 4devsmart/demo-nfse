<?php

declare(strict_types=1);

namespace App\Actions\Cidades;

use App\Models\Cidade;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carrega a tabela de municipios do IBGE. A fonte padrao e o arquivo que
 * acompanha o projeto, para que a demonstracao suba sem internet; `daApiDoIbge`
 * atualiza a partir do servico oficial quando ha rede.
 */
final readonly class ImportarCidades
{
    private const LOTE = 500;

    public function __construct(private FonteDeCidades $fonte) {}

    public function executar(): int
    {
        $municipios = $this->fonte->municipios();

        if ($municipios === []) {
            throw new RuntimeException(__('A fonte não devolveu nenhum município.'));
        }

        DB::transaction(function () use ($municipios): void {
            foreach (array_chunk($municipios, self::LOTE) as $lote) {
                Cidade::query()->upsert($this->comCarimboDeTempo($lote), ['codigo_ibge'], ['nome', 'uf', 'updated_at']);
            }
        });

        return count($municipios);
    }

    /**
     * @param  list<array{codigo_ibge: string, nome: string, uf: string}>  $lote
     * @return list<array<string, string>>
     */
    private function comCarimboDeTempo(array $lote): array
    {
        $agora = now()->toDateTimeString();

        return array_map(
            static fn (array $municipio): array => [...$municipio, 'created_at' => $agora, 'updated_at' => $agora],
            $lote,
        );
    }
}
