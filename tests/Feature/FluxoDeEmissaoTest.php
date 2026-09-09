<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Consultas\GuiaDeEmissao;
use App\Domain\Enums\StatusNota;
use App\Fiscal\Excecoes\CodigoDeFalha;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A página "Fluxo da emissão" lista os estados da nota e os códigos de erro a
 * partir dos próprios enums, sem texto duplicado.
 *
 * Com isso a página não envelhece, mas um estado ou um código novo passa a
 * aparecer nela sem que ninguém escreva uma linha, e uma frase vazia passaria
 * despercebida. Aqui a página é conferida contra os enums, caso a caso.
 */
class FluxoDeEmissaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_a_pagina_lista_todas_as_situacoes_com_significado_e_proximo_passo(): void
    {
        $resposta = $this->get('/admin/fluxo-de-emissao')->assertOk();

        foreach (StatusNota::cases() as $status) {
            $resposta->assertSee($status->getLabel());
            $resposta->assertSee($status->significado());
            $resposta->assertSee($status->proximoPasso());
        }
    }

    public function test_a_pagina_lista_todos_os_codigos_de_falha_e_se_repetir_e_seguro(): void
    {
        $resposta = $this->get('/admin/fluxo-de-emissao')->assertOk();

        foreach (CodigoDeFalha::cases() as $codigo) {
            $resposta->assertSee($codigo->value);
            $resposta->assertSee($codigo->significado());
            $resposta->assertSee($codigo->oQueFazer());
        }
    }

    /**
     * Cada situação e cada código aparecem uma vez, na ordem em que o enum os
     * declara.
     */
    public function test_o_guia_espelha_os_enums_um_a_um(): void
    {
        $guia = app(GuiaDeEmissao::class);

        $this->assertSame(
            StatusNota::cases(),
            array_map(fn (array $estado): StatusNota => $estado['situacao'], $guia->estados()),
        );

        $this->assertSame(
            CodigoDeFalha::cases(),
            array_map(fn (array $falha): CodigoDeFalha => $falha['codigo'], $guia->falhas()),
        );
    }

    /**
     * Os três passos explicam por que a emissão são duas chamadas. A terceira
     * linha registra que "Emitir" apenas encadeia as outras duas.
     */
    public function test_o_guia_explica_as_duas_chamadas_e_o_atalho(): void
    {
        $passos = app(GuiaDeEmissao::class)->passos();

        $this->assertSame(
            ['POST /v1/nfse/xml', 'POST /v1/nfse/transmissao', 'as duas, em sequência'],
            array_map(fn (array $passo): string => $passo['chamada'], $passos),
        );

        $this->get('/admin/fluxo-de-emissao')->assertSee('POST /v1/nfse/transmissao');
    }
}
