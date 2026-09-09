<?php

declare(strict_types=1);

namespace App\Actions\Enderecos;

use App\Domain\ValueObjects\CodigoIbge;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as ClienteHttp;
use RuntimeException;

/**
 * Preenche o endereco a partir do CEP. E conveniencia de cadastro, nao regra
 * fiscal: se o servico estiver fora do ar, o formulario continua preenchivel a
 * mao, por isso a falha vira mensagem, nunca bloqueio.
 */
final readonly class BuscarEnderecoPeloCep
{
    /** Ver `WrapperFiscal`: o aperto de mao nao usa o timeout de leitura. */
    private const SEGUNDOS_PARA_CONECTAR = 3;

    private const DIGITOS_DO_CEP = 8;

    private const DIAS_EM_CACHE = 30;

    public function __construct(
        private ClienteHttp $http,
        private Cache $cache,
        private string $url,
        private int $segundosDeTimeout = 8,
    ) {}

    /**
     * O cache guarda o corpo da resposta, e nao o objeto pronto. O
     * `unserialize()` do PHP nao dispara autoload, porque o
     * `unserialize_callback_func` vem vazio, e o acerto de cache e justamente o
     * caminho em que a classe do DTO ainda nao foi carregada no processo: o
     * objeto voltava como `__PHP_Incomplete_Class` e estourava no tipo de
     * retorno. Array nao tem esse problema, e ainda sobrevive a renomear campo.
     *
     * A chave carrega a origem porque o formato do corpo e dela: trocar de
     * servico invalida o que estava guardado, sem depender de alguem limpar o
     * cache na hora do deploy.
     */
    public function executar(string $cep): EnderecoEncontrado
    {
        $digitos = preg_replace('/\D/', '', $cep) ?? '';

        if (strlen($digitos) !== self::DIGITOS_DO_CEP) {
            throw new RuntimeException(__('O CEP precisa ter 8 dígitos.'));
        }

        $corpo = $this->cache->remember(
            "cep:brasilapi:{$digitos}",
            now()->addDays(self::DIAS_EM_CACHE),
            fn (): array => $this->consultar($digitos),
        );

        return $this->enderecoDe((array) $corpo, $digitos);
    }

    /**
     * @return array<string, mixed>
     */
    private function consultar(string $digitos): array
    {
        try {
            $resposta = $this->http
                ->connectTimeout(self::SEGUNDOS_PARA_CONECTAR)
                ->timeout($this->segundosDeTimeout)
                ->acceptJson()
                ->get(str_replace('{cep}', $digitos, $this->url));
        } catch (ConnectionException $falha) {
            throw new RuntimeException(__('A busca de CEP não respondeu: :motivo', ['motivo' => $falha->getMessage()]));
        }

        if ($resposta->failed()) {
            throw new RuntimeException(__('CEP :cep não encontrado.', ['cep' => $digitos]));
        }

        return (array) $resposta->json();
    }

    /**
     * @param  array<string, mixed>  $corpo
     */
    private function enderecoDe(array $corpo, string $digitos): EnderecoEncontrado
    {
        return new EnderecoEncontrado(
            logradouro: (string) ($corpo['street'] ?? ''),
            bairro: (string) ($corpo['neighborhood'] ?? ''),
            localidade: (string) ($corpo['city'] ?? ''),
            uf: (string) ($corpo['state'] ?? ''),
            municipio: $this->municipioDe($corpo),
            cep: $digitos,
        );
    }

    /**
     * O codigo IBGE vem aninhado em `ibge.city`, e nem todo servico por tras da
     * BrasilAPI o devolve: a v2 corre varias fontes e responde com a primeira.
     * CEP sem municipio nao e erro, o resto do endereco continua util e a
     * cidade se escolhe a mao.
     *
     * @param  array<string, mixed>  $corpo
     */
    private function municipioDe(array $corpo): ?CodigoIbge
    {
        $ibge = $corpo['ibge'] ?? null;
        $codigo = is_array($ibge) ? (string) ($ibge['city'] ?? '') : '';

        return CodigoIbge::ehValido($codigo) ? CodigoIbge::deSeteDigitos($codigo) : null;
    }
}
