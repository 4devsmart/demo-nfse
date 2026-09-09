<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\StatusNota;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Excecoes\CodigoDeFalha;
use App\Fiscal\Excecoes\FalhaFiscal;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Response as Status;
use Tests\Apoio\GatewayFiscalFalso;
use Tests\TestCase;

/**
 * O DANFSE e desenhado pela API, nunca por esta aplicacao, e so a partir do
 * XML autorizado.
 */
class DanfseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(GatewayFiscal::class, new GatewayFiscalFalso);
        $this->actingAs(User::factory()->create());
    }

    public function test_a_nota_autorizada_abre_o_pdf_da_api_na_aba(): void
    {
        $resposta = $this->get(route('notas.danfse', $this->notaAutorizada()));

        $resposta->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="danfse-202600000001.pdf"');

        $conteudo = $resposta->getContent();
        $this->assertIsString($conteudo);
        $this->assertStringStartsWith('%PDF', $conteudo);
    }

    public function test_sem_xml_autorizado_nao_ha_danfse(): void
    {
        $this->get(route('notas.danfse', Nota::factory()->create()))
            ->assertNotFound();
    }

    public function test_o_danfse_exige_login(): void
    {
        $this->app->forgetInstance(GatewayFiscal::class);
        auth()->logout();

        $this->get(route('notas.danfse', $this->notaAutorizada()))->assertRedirect('/login');
    }

    private function notaAutorizada(): Nota
    {
        return Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'status' => StatusNota::Autorizada,
            'numero_nfse' => '202600000001',
            'xml_autorizado' => base64_encode('<NFSe/>'),
        ]);
    }

    /**
     * Quem desenha o impresso e a API, entao falha dela e desfecho normal desta
     * rota, e nao defeito deste sistema.
     *
     * O defeito que este teste tranca apareceu emitindo de verdade: a biblioteca
     * fiscal recusou desenhar uma nota autorizada de Porto Alegre, e o operador
     * recebeu a pagina de erro do Laravel com a pilha inteira. Em producao, com
     * `APP_DEBUG` desligado, receberia "Server Error" e mais nada.
     */
    public function test_falha_da_api_vira_motivo_legivel_e_nao_pagina_de_erro(): void
    {
        $gateway = new GatewayFiscalFalso;
        $gateway->falharNoDanfseCom(new FalhaFiscal(
            CodigoDeFalha::FalhaNaLib->value,
            'o motor fiscal de NFS-e falhou: NFSE_CarregarXML falhou: -10',
        ));
        $this->app->instance(GatewayFiscal::class, $gateway);

        $this->get(route('notas.danfse', $this->notaAutorizada()))
            ->assertStatus(Status::HTTP_BAD_GATEWAY)
            ->assertSee('NFSE_CarregarXML falhou: -10');
    }
}
