<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DocumentacaoFiscalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_documentacao_exige_login(): void
    {
        $this->get('/docs')->assertRedirect('/login');
        $this->get('/openapi.yaml')->assertRedirect('/login');
    }

    public function test_espelha_a_pagina_do_swagger_da_api_fiscal(): void
    {
        Http::fake([
            'http://fiscal-api:8080/docs' => Http::response('<html>swagger</html>', 200, ['Content-Type' => 'text/html; charset=utf-8']),
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/docs')
            ->assertOk()
            ->assertSee('swagger', escape: false)
            ->assertHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function test_espelha_a_especificacao_no_mesmo_caminho_que_o_html_referencia(): void
    {
        Http::fake([
            'http://fiscal-api:8080/openapi.yaml' => Http::response('openapi: 3.1.0', 200, ['Content-Type' => 'application/yaml']),
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/openapi.yaml')
            ->assertOk()
            ->assertSee('openapi: 3.1.0')
            ->assertHeader('Content-Type', 'application/yaml');
    }

    /**
     * O HTML do Swagger referencia os proprios arquivos por caminho relativo, e
     * por isso as rotas mantem `/docs/*`. Sem o tipo de conteudo original, o
     * navegador recusa o JS e a pagina abre em branco.
     */
    public function test_os_arquivos_do_swagger_mantem_o_caminho_e_o_tipo(): void
    {
        Http::fake([
            'http://fiscal-api:8080/docs/swagger-ui.js' => Http::response(
                'console.log(1)',
                200,
                ['Content-Type' => 'application/javascript'],
            ),
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/docs/swagger-ui.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript');
    }

    /**
     * Resposta sem `Content-Type` acontece com proxy no meio. Devolver o padrao
     * binario e melhor que devolver header vazio, que alguns navegadores
     * interpretam como HTML.
     */
    public function test_resposta_sem_tipo_declarado_cai_no_binario(): void
    {
        Http::fake(['http://fiscal-api:8080/docs' => Http::response('conteudo', 200, ['Content-Type' => ''])]);

        $this->actingAs(User::factory()->create())
            ->get('/docs')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream');
    }

    /**
     * `FISCAL_API_URL` pode chegar com barra no fim, e aqui a URL é montada por
     * concatenação, não por `baseUrl()`: o espelho entrega o endereço já
     * absoluto ao cliente HTTP, e o Laravel só apara a base quando o caminho é
     * relativo (`PendingRequest::send()`). Sem o `rtrim` a chamada vira
     * `//docs`, que é outro caminho, e o Swagger não abre.
     *
     * A asserção precisa ser negativa. O cliente HTTP grava duas entradas
     * para uma requisição a `//docs`: a que foi pedida e a forma normalizada.
     * Um `assertSent` conferindo igualdade com `/docs` passa nos dois casos,
     * porque a normalizada está sempre lá, e não serviria de guarda nenhuma.
     */
    public function test_a_barra_sobrando_na_url_da_api_nao_dobra_no_espelho(): void
    {
        config(['fiscal.url' => 'http://fiscal-api:8080/']);

        Http::fake(['http://fiscal-api:8080/docs' => Http::response('<html>swagger</html>')]);

        $this->actingAs(User::factory()->create())->get('/docs')->assertOk();

        Http::assertNotSent(fn ($requisicao): bool => str_contains($requisicao->url(), ':8080//'));
    }

    public function test_a_api_fora_do_ar_devolve_502_legivel(): void
    {
        Http::fake(fn () => throw new ConnectionException('sem rota para o host'));

        $this->actingAs(User::factory()->create())
            ->get('/docs')
            ->assertStatus(502)
            ->assertSee('A API fiscal não respondeu')
            // O motivo e a metade util: sem ele a pagina diz que falhou e nao diz por que.
            ->assertSee('sem rota para o host')
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * O `/v1` nao e espelhado: espelha-lo de forma util exigiria mandar o token
     * do servidor, e ai a pagina de documentacao viraria um proxy autenticado
     * para emitir documento fiscal. `..` no nome do arquivo e o caminho por onde
     * uma chamada escapa desse recorte.
     */
    public function test_o_espelho_nao_sai_da_pasta_de_documentacao(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->get('/docs/..')
            ->assertNotFound();

        Http::assertNothingSent();
    }
}
