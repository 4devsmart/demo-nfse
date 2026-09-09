<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Enderecos\BuscarEnderecoPeloCep;
use App\Consultas\BuscaDeCidades;
use App\Models\Cidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class BuscarEnderecoPeloCepTest extends TestCase
{
    use RefreshDatabase;

    private const CEP = '20031170';

    public function test_traz_o_endereco_e_o_codigo_ibge_do_municipio(): void
    {
        $this->responderCom([
            'street' => 'Avenida Rio Branco',
            'neighborhood' => 'Centro',
            'city' => 'Rio de Janeiro',
            'state' => 'RJ',
            'ibge' => ['city' => '3304557', 'state' => '33'],
        ]);

        $endereco = app(BuscarEnderecoPeloCep::class)->executar('20031-170');

        $this->assertSame('Avenida Rio Branco', $endereco->logradouro);
        $this->assertSame('Centro', $endereco->bairro);
        $this->assertSame('Rio de Janeiro', $endereco->localidade);
        $this->assertSame('RJ', $endereco->uf);
        $this->assertSame('3304557', (string) $endereco->municipio);
        $this->assertSame('20031170', $endereco->cep);

        // O CEP entra no lugar do `{cep}` da URL configurada, so com digitos.
        Http::assertSent(fn ($requisicao): bool => str_contains($requisicao->url(), '20031170'));
    }

    public function test_o_codigo_ibge_liga_o_cep_a_cidade_cadastrada(): void
    {
        $cidade = Cidade::factory()->rioDeJaneiro()->create();
        $this->responderCom(['ibge' => ['city' => '3304557'], 'street' => '', 'neighborhood' => '', 'city' => '', 'state' => 'RJ']);

        $endereco = app(BuscarEnderecoPeloCep::class)->executar(self::CEP);

        $this->assertNotNull($endereco->municipio);
        $this->assertSame(
            $cidade->getKey(),
            app(BuscaDeCidades::class)->idPeloCodigoIbge($endereco->municipio),
        );
    }

    public function test_cep_com_tamanho_errado_nem_chega_a_sair(): void
    {
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('8 dígitos');

        app(BuscarEnderecoPeloCep::class)->executar('123');

        Http::assertNothingSent();
    }

    public function test_cep_inexistente_vira_mensagem_e_nao_quebra_o_cadastro(): void
    {
        $this->responderCom(['name' => 'CepPromiseError', 'message' => 'Todos os serviços de CEP retornaram erro.'], 404);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CEP 99999999 não encontrado.');

        app(BuscarEnderecoPeloCep::class)->executar('99999999');
    }

    public function test_servico_fora_do_ar_vira_mensagem(): void
    {
        Http::fake(fn () => throw new ConnectionException('sem rota para o host'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A busca de CEP não respondeu: sem rota para o host');

        app(BuscarEnderecoPeloCep::class)->executar(self::CEP);
    }

    /**
     * @param  array<string, mixed>  $corpo
     */
    private function responderCom(array $corpo, int $status = 200): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response($corpo, $status)]);
    }

    /**
     * O codigo vem aninhado em `ibge.city`, e nao ha contrato que garanta o
     * tipo. E ele que liga o CEP ao provedor de NFS-e.
     */
    public function test_o_codigo_ibge_numerico_ainda_liga_o_cep_ao_municipio(): void
    {
        $this->responderCom(['street' => 'Rua X', 'ibge' => ['city' => 3304557]]);

        $this->assertSame('3304557', (string) app(BuscarEnderecoPeloCep::class)->executar('20031-170')->municipio);
    }

    /**
     * A v2 corre varios servicos de CEP e responde com o primeiro que voltar.
     * Nem todos mandam o codigo IBGE, e isso nao e erro: o resto do endereco
     * continua util, e a cidade se escolhe a mao.
     */
    public function test_cep_sem_codigo_ibge_preenche_o_resto_do_endereco(): void
    {
        $this->responderCom(['street' => 'Rua X', 'neighborhood' => 'Centro']);

        $endereco = app(BuscarEnderecoPeloCep::class)->executar('20031-170');

        $this->assertNull($endereco->municipio);
        $this->assertSame('Rua X', $endereco->logradouro);
        $this->assertSame('', $endereco->uf);
    }

    /**
     * A segunda busca do mesmo CEP nao sai: endereco de CEP nao muda no
     * intervalo de um cadastro.
     */
    public function test_o_mesmo_cep_nao_e_perguntado_duas_vezes(): void
    {
        $this->responderCom(['street' => 'Rua X', 'ibge' => ['city' => '3304557']]);

        $primeiro = app(BuscarEnderecoPeloCep::class)->executar('20031-170');
        $segundo = app(BuscarEnderecoPeloCep::class)->executar('20031170');

        Http::assertSentCount(1);
        $this->assertEquals($primeiro, $segundo, 'o endereço remontado do cache tem que ser o mesmo');
    }

    /**
     * O que vai para o cache e o corpo da resposta, e nao o objeto pronto.
     * `unserialize()` nao dispara autoload, e o acerto de cache e o caminho em
     * que a classe do DTO ainda nao foi carregada no processo: o objeto voltava
     * como `__PHP_Incomplete_Class` e estourava no tipo de retorno.
     */
    public function test_o_cache_guarda_o_corpo_da_resposta_e_nao_o_objeto(): void
    {
        $this->responderCom(['street' => 'Rua X', 'ibge' => ['city' => '3304557']]);

        app(BuscarEnderecoPeloCep::class)->executar(self::CEP);

        $this->assertIsArray(Cache::get('cep:brasilapi:'.self::CEP));
    }
}
