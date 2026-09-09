<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Nenhuma requisicao sai daqui para a rede. Sem isto, teste que esquece o
     * `Http::fake()` sai batendo na BrasilAPI, no IBGE ou no portal da SVRS: em
     * CI vira falha intermitente, e na maquina de quem desenvolve vira teste
     * que passa por causa da internet.
     *
     * Quem falha assim recebe do proprio Laravel o endereco que tentou chamar,
     * que e o que falta descobrir nesse tipo de erro.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }
}
