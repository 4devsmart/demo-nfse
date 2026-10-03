<?php

declare(strict_types=1);

namespace App\Actions\Municipios;

use App\Domain\ValueObjects\CodigoIbge;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Respostas\MunicipioAtendido;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Descobre quem atende o municipio antes de montar qualquer coisa. A tabela de
 * provedores muda com versao da biblioteca, nao com o dia: cachear e barato.
 *
 * E por isso que a chave leva o commit da API. Antes ela levava so o codigo, e
 * trocar a imagem da API deixava a tela mostrando o leiaute antigo por ate um
 * dia: Brasilia seguia "ISSNet (abrasf)" depois que a API nova ja respondia
 * `padrao_nacional`.
 */
final readonly class ConsultarSuporteDoMunicipio
{
    private const HORAS_EM_CACHE = 24;

    /**
     * Quanto tempo o commit da API vale sem perguntar de novo. E a demora maxima
     * para a troca de imagem aparecer, e o custo e uma ida ao `/v1/ping` por
     * intervalo, e nao uma por consulta.
     */
    private const MINUTOS_DA_VERSAO = 10;

    private const CHAVE_DA_VERSAO = 'versao-da-api-fiscal';

    public function __construct(
        private GatewayFiscal $gateway,
        private Cache $cache,
    ) {}

    public function executar(CodigoIbge $codigo): MunicipioAtendido
    {
        $guardado = $this->cache->remember(
            $this->chave($codigo, $this->versaoDaApi()),
            now()->addHours(self::HORAS_EM_CACHE),
            fn (): array => $this->corpoDe($this->gateway->municipio($codigo)),
        );

        return MunicipioAtendido::doCorpoDaResposta($guardado);
    }

    /**
     * A resposta que ja esta guardada, sem sair para a rede. E o que permite a
     * `PreverProvedorDoMunicipio` responder sem tentar a chamada, e sem que a
     * falha guardada de minutos atras esconda uma resposta boa que chegou
     * depois, pelo botao do cadastro.
     *
     * Sem commit guardado nao ha como saber a que versao da API a resposta
     * pertence, e sair para a rede aqui quebraria o contrato do metodo: a
     * resposta e `null`, e quem pergunta segue para `executar()`.
     */
    public function jaConsultado(CodigoIbge $codigo): ?MunicipioAtendido
    {
        $versao = $this->cache->get(self::CHAVE_DA_VERSAO);

        if (! is_string($versao)) {
            return null;
        }

        $guardado = $this->cache->get($this->chave($codigo, $versao));

        return is_array($guardado) ? MunicipioAtendido::doCorpoDaResposta($guardado) : null;
    }

    /**
     * O que vai para o cache e o corpo cru da resposta, e nao o objeto.
     *
     * Guardar objeto parecia funcionar e nao funcionava: `serializable_classes`
     * vem `false` no `config/cache.php`, que e o padrao do Laravel, e com ele o
     * `unserialize` do cache de banco nao aceita classe nenhuma. A primeira
     * chamada devolvia o objeto recem-criado e a segunda devolvia
     * `__PHP_Incomplete_Class`, o que estourava no tipo de retorno.
     *
     * A suite inteira passava por cima disso porque em teste o cache e `array`,
     * que guarda o objeto em memoria e nunca serializa. So aparecia rodando.
     *
     * Corpo cru tambem e o que torna a leitura independente do driver, e reusa a
     * mesma porta de entrada que a resposta HTTP usa.
     *
     * @return array{codigo: string, provedor: string, layout: string, suportado: bool}
     */
    private function corpoDe(MunicipioAtendido $municipio): array
    {
        return [
            'codigo' => (string) $municipio->codigo,
            'provedor' => $municipio->provedor,
            'layout' => $municipio->layout,
            'suportado' => $municipio->suportado,
        ];
    }

    private function versaoDaApi(): string
    {
        return (string) $this->cache->remember(
            self::CHAVE_DA_VERSAO,
            now()->addMinutes(self::MINUTOS_DA_VERSAO),
            fn (): string => $this->gateway->identificacao()->commit,
        );
    }

    private function chave(CodigoIbge $codigo, string $versao): string
    {
        return "municipio-nfse:{$versao}:{$codigo}";
    }
}
