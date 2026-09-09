<?php

declare(strict_types=1);

namespace App\Actions\Cidades;

use Illuminate\Http\Client\Factory as ClienteHttp;
use RuntimeException;

/**
 * O servico oficial de localidades do IBGE. So e usado quando alguem pede a
 * atualizacao: a carga do dia a dia sai do arquivo local.
 */
final readonly class ApiDoIbge implements FonteDeCidades
{
    /** Ver `WrapperFiscal`: o aperto de mao nao usa o timeout de leitura. */
    private const SEGUNDOS_PARA_CONECTAR = 3;

    public function __construct(
        private ClienteHttp $http,
        private string $url,
        private int $segundosDeTimeout = 60,
    ) {}

    public function municipios(): array
    {
        $resposta = $this->http
            ->connectTimeout(self::SEGUNDOS_PARA_CONECTAR)
            ->timeout($this->segundosDeTimeout)
            ->acceptJson()
            ->get($this->url);

        if ($resposta->failed()) {
            throw new RuntimeException(__('O IBGE respondeu :status.', ['status' => $resposta->status()]));
        }

        return array_values(array_filter(array_map(
            fn (array $municipio): ?array => $this->traduzir($municipio),
            (array) $resposta->json(),
        )));
    }

    /**
     * @param  array<string, mixed>  $municipio
     * @return array{codigo_ibge: string, nome: string, uf: string}|null
     */
    private function traduzir(array $municipio): ?array
    {
        $uf = $this->siglaDaUf($municipio);

        if ($uf === null) {
            return null;
        }

        return [
            'codigo_ibge' => (string) ($municipio['id'] ?? ''),
            'nome' => (string) ($municipio['nome'] ?? ''),
            'uf' => $uf,
        ];
    }

    /**
     * A UF vem aninhada em duas hierarquias diferentes conforme o municipio.
     *
     * @param  array<string, mixed>  $municipio
     */
    private function siglaDaUf(array $municipio): ?string
    {
        $caminhos = [
            ['microrregiao', 'mesorregiao', 'UF', 'sigla'],
            ['regiao-imediata', 'regiao-intermediaria', 'UF', 'sigla'],
        ];

        foreach ($caminhos as $caminho) {
            $sigla = data_get($municipio, implode('.', $caminho));

            if (is_string($sigla) && $sigla !== '') {
                return $sigla;
            }
        }

        return null;
    }
}
