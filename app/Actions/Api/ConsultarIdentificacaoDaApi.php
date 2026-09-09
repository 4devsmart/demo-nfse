<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Respostas\IdentificacaoDaApi;

/**
 * Quem esta do outro lado: build, commit e as rotas que a API publica.
 *
 * Nao cacheia, ao contrario de `ConsultarSuporteDoMunicipio`. A resposta aqui
 * so serve para dizer o que esta no ar agora, e uma versao guardada de ontem
 * responderia a pergunta errada.
 */
final readonly class ConsultarIdentificacaoDaApi
{
    public function __construct(private GatewayFiscal $gateway) {}

    public function executar(): IdentificacaoDaApi
    {
        return $this->gateway->identificacao();
    }
}
