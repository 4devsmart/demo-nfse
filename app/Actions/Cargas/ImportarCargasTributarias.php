<?php

declare(strict_types=1);

namespace App\Actions\Cargas;

use App\Models\CargaTributariaAproximada;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carrega a TabelaIBPTax dos itens da LC 116.
 *
 * So ha fonte local, e a razao nao e comodidade: o IBPT distribui a tabela sob
 * cadastro, em <https://deolhonoimposto.ibpt.org.br>, com CNPJ e um arquivo por
 * UF. Nao ha endereco publico e estavel para apontar, entao qualquer
 * "atualizar pela origem" escrito aqui estaria mentindo sobre de onde o dado
 * veio.
 *
 * O arquivo que acompanha o projeto foi gerado do formato oficial
 * (`TabelaIBPTax{UF}{versao}.csv`, coluna `tipo` = 2, que sao os itens da LC
 * 116), versao 26.2.A. Trocar por uma tabela nova e substituir o arquivo e
 * rodar isto de novo.
 */
final readonly class ImportarCargasTributarias
{
    private const LOTE = 500;

    public function __construct(private FonteDeCargas $fonte) {}

    public function executar(): int
    {
        $cargas = $this->fonte->cargas();

        if ($cargas === []) {
            throw new RuntimeException(__('A fonte não devolveu nenhuma carga tributária.'));
        }

        DB::transaction(function () use ($cargas): void {
            $agora = now()->toDateTimeString();

            foreach (array_chunk($cargas, self::LOTE) as $lote) {
                $comCarimbo = array_map(
                    static fn (array $carga): array => [...$carga, 'created_at' => $agora, 'updated_at' => $agora],
                    $lote,
                );

                CargaTributariaAproximada::query()->upsert($comCarimbo, ['codigo', 'uf'], [
                    'descricao', 'percentual_federal', 'percentual_federal_importado',
                    'percentual_estadual', 'percentual_municipal',
                    'vigencia_inicio', 'vigencia_fim', 'versao', 'updated_at',
                ]);
            }
        });

        return count($cargas);
    }
}
