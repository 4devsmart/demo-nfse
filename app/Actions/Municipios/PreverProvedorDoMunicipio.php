<?php

declare(strict_types=1);

namespace App\Actions\Municipios;

use App\Domain\ValueObjects\CodigoIbge;
use App\Fiscal\Respostas\MunicipioAtendido;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * A mesma pergunta que `ConsultarSuporteDoMunicipio` faz, com outro contrato de
 * erro: aqui nada estoura. Existe separada porque quem pergunta e diferente. O
 * botao do cadastro pergunta porque alguem clicou, e precisa ver a falha; o
 * formulario da nota pergunta sozinho, enquanto se digita, e falha ali nao pode
 * virar tela de erro no meio de uma emissao.
 *
 * A resposta boa vale um dia, e quem a guarda e `ConsultarSuporteDoMunicipio`.
 * A falha vale minutos, e e guardada aqui: sem isso, API fora do ar faria o
 * formulario tentar a rede a cada re-render, e o aviso viraria espera.
 */
final readonly class PreverProvedorDoMunicipio
{
    private const MINUTOS_APOS_FALHA = 5;

    public function __construct(
        private ConsultarSuporteDoMunicipio $consultar,
        private Cache $cache,
    ) {}

    public function executar(CodigoIbge $codigo): ProvedorPrevisto
    {
        // A resposta boa vem antes da falha guardada: quem clicou "Verificar
        // município" no cadastro depois da queda ja encheu o cache, e insistir
        // no "nao deu para consultar" por mais alguns minutos seria esconder
        // uma resposta que esta ali.
        $guardado = $this->consultar->jaConsultado($codigo);

        if ($guardado instanceof MunicipioAtendido) {
            return ProvedorPrevisto::de($guardado);
        }

        if ($this->cache->has($this->chaveDaFalha($codigo))) {
            return ProvedorPrevisto::naoConsultado();
        }

        try {
            return ProvedorPrevisto::de($this->consultar->executar($codigo));
        } catch (Throwable) {
            $this->cache->put($this->chaveDaFalha($codigo), true, now()->addMinutes(self::MINUTOS_APOS_FALHA));

            return ProvedorPrevisto::naoConsultado();
        }
    }

    private function chaveDaFalha(CodigoIbge $codigo): string
    {
        return "municipio-nfse-indisponivel:{$codigo}";
    }
}
