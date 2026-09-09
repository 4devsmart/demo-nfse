<?php

declare(strict_types=1);

namespace App\Actions\Servicos;

use App\Models\CodigoDeTributacaoNacional;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carrega a tabela `cTribNac`. A fonte padrao e o arquivo que acompanha o
 * projeto, para que a demonstracao suba sem internet;
 * `AtualizarCodigosPeloPortalNacional` recarrega da origem quando ha rede.
 */
final readonly class ImportarCodigosDeTributacaoNacional
{
    private const LOTE = 200;

    /**
     * Quanto da tabela de referencia a fonte precisa devolver para a leitura
     * contar como completa. Codigo revogado some da origem, entao encolher um
     * pouco e normal; encolher muito e pagina lida pela metade.
     */
    private const FRACAO_MINIMA = 0.8;

    public function __construct(
        private FonteDeCodigosDeTributacao $fonte,
        private FonteDeCodigosDeTributacao $referencia,
    ) {}

    public function executar(): int
    {
        $codigos = $this->fonte->codigos();

        if ($codigos === []) {
            throw new RuntimeException(__('A fonte não devolveu nenhum código de tributação nacional.'));
        }

        $this->recusarLeituraPelaMetade(count($codigos));

        DB::transaction(function () use ($codigos): void {
            $agora = now()->toDateTimeString();

            foreach (array_chunk($codigos, self::LOTE) as $lote) {
                $carimbado = array_map(
                    static fn (array $codigo): array => [...$codigo, 'created_at' => $agora, 'updated_at' => $agora],
                    $lote,
                );

                CodigoDeTributacaoNacional::query()->upsert($carimbado, ['codigo'], [
                    'item_lista_servico', 'descricao', 'updated_at',
                ]);
            }
        });

        return count($codigos);
    }

    /**
     * A gravacao e `upsert`, e nao apaga-e-recria: leitura pela metade nao
     * esvazia a tabela, mistura o que veio com o que ficou e devolve um numero
     * verde na tela. O unico jeito de isso aparecer e recusar antes de gravar.
     *
     * A comparacao e contra o arquivo que acompanha o projeto, e nao contra a
     * tabela carregada. A tabela e a medida errada: como o `upsert` nunca apaga,
     * a contagem dela so sobe, e uma revisao que revogue mais do que inclua a
     * empurraria para cima de vez. A partir dai a origem honesta seria sempre
     * "leitura incompleta", e a atualizacao ficaria travada para sempre, sem
     * nada na tela que a destravasse.
     */
    private function recusarLeituraPelaMetade(int $lidos): void
    {
        $referencia = count($this->referencia->codigos());

        if ($referencia === 0 || $lidos >= (int) floor($referencia * self::FRACAO_MINIMA)) {
            return;
        }

        throw new RuntimeException(__(
            'A fonte devolveu :lidos códigos, contra :referencia do arquivo do projeto. Parece leitura incompleta, e nada foi gravado.',
            ['lidos' => $lidos, 'referencia' => $referencia],
        ));
    }
}
