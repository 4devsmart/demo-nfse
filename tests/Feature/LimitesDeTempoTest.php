<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O limite de execução do PHP tem que sobrar sobre o timeout da API fiscal.
 *
 * O relógio do FrankenPHP conta tempo de parede, INCLUSIVE espera de rede, ao
 * contrário do timer clássico do PHP em Unix, que ignora I/O. Medido nesta
 * imagem: um `sleep(40)` morre em 30,0s exatos com o default.
 *
 * Se o PHP matar o processo antes de a chamada fiscal estourar, o fatal error
 * não é capturável: `TransmitirNota` nunca chega ao `catch (DesfechoIndeterminado)`
 * que marca a nota, e sobra uma DPS gerada com uma NFS-e possivelmente
 * autorizada do outro lado, sem registro nenhum da dúvida. É exatamente o
 * cenário que o resto do sistema existe para evitar.
 *
 * O acoplamento entre um `.ini` do Docker e um `config/` do Laravel é invisível
 * de dentro dos dois. Este teste é o que o torna visível.
 */
class LimitesDeTempoTest extends TestCase
{
    private const MARGEM_MINIMA_EM_SEGUNDOS = 30;

    /**
     * @return array<string, array{string}>
     */
    public static function arquivosDeConfiguracaoDoPhp(): array
    {
        return [
            'desenvolvimento' => ['docker/php.dev.ini'],
            'produção' => ['docker/php.prod.ini'],
        ];
    }

    #[DataProvider('arquivosDeConfiguracaoDoPhp')]
    public function test_o_limite_do_php_sobra_sobre_o_timeout_fiscal(string $arquivo): void
    {
        $limite = $this->maxExecutionTimeDe($arquivo);
        $timeoutFiscal = (int) config('fiscal.timeout');

        $this->assertGreaterThan(0, $timeoutFiscal);
        $this->assertGreaterThanOrEqual(
            $timeoutFiscal + self::MARGEM_MINIMA_EM_SEGUNDOS,
            $limite,
            "{$arquivo} precisa deixar pelo menos ".self::MARGEM_MINIMA_EM_SEGUNDOS
            ." segundos de folga sobre o timeout da API fiscal ({$timeoutFiscal}s).",
        );
    }

    /**
     * O `.env.example` é o que alguém copia para subir o projeto. Se ele pedir
     * uma espera maior que a que o PHP tolera, o defeito nasce no primeiro
     * `cp .env.example .env`.
     */
    public function test_o_env_de_exemplo_nao_pede_mais_espera_que_o_php_tolera(): void
    {
        $exemplo = (string) file_get_contents(base_path('.env.example'));

        if (preg_match('/^FISCAL_API_TIMEOUT=(\d+)$/m', $exemplo, $encontrado) !== 1) {
            $this->fail('O .env.example precisa declarar FISCAL_API_TIMEOUT.');
        }

        foreach (array_column(self::arquivosDeConfiguracaoDoPhp(), 0) as $arquivo) {
            $this->assertGreaterThanOrEqual(
                (int) $encontrado[1] + self::MARGEM_MINIMA_EM_SEGUNDOS,
                $this->maxExecutionTimeDe($arquivo),
                "{$arquivo} não tolera o FISCAL_API_TIMEOUT do .env.example.",
            );
        }
    }

    /**
     * Um limite não declarado é o default de 30s, e o default é o defeito.
     */
    private function maxExecutionTimeDe(string $arquivo): int
    {
        $conteudo = (string) file_get_contents(base_path($arquivo));

        if (preg_match('/^max_execution_time\s*=\s*(\d+)$/m', $conteudo, $encontrado) !== 1) {
            $this->fail("{$arquivo} precisa declarar max_execution_time: sem isso vale o default de 30s.");
        }

        return (int) $encontrado[1];
    }
}
