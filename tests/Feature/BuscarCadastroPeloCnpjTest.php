<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Cadastros\BuscarCadastroPeloCnpj;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Apoio\CadastroDaReceita;
use Tests\TestCase;

/**
 * A consulta que preenche o cadastro pelo CNPJ. Como a de CEP, e conveniencia:
 * nada aqui pode impedir que alguem termine o cadastro digitando.
 */
class BuscarCadastroPeloCnpjTest extends TestCase
{
    private const CNPJ = '45543915000181';

    public function test_traz_o_cadastro_para_preencher_o_formulario(): void
    {
        $this->responderCom(CadastroDaReceita::corpo());

        $cadastro = app(BuscarCadastroPeloCnpj::class)->executar('45.543.915/0001-81');

        $this->assertSame('CARREFOUR COMERCIO E INDUSTRIA LTDA', $cadastro->razaoSocial);
        $this->assertSame('CARREFOUR', $cadastro->nomeFantasia);
        $this->assertSame('4711301', $cadastro->cnaePrincipal);
        $this->assertSame('1199999999', $cadastro->telefone);
        $this->assertSame('contas@exemplo.test', $cadastro->email);
        $this->assertTrue($cadastro->estaAtiva());

        $this->assertSame('TAMBORE', $cadastro->endereco->bairro);
        $this->assertSame('125', $cadastro->endereco->numero);
        $this->assertSame('BLOCO C SALA 1 C101', $cadastro->endereco->complemento);
        $this->assertSame('06460020', $cadastro->endereco->cep);
        $this->assertSame('BARUERI', $cadastro->endereco->localidade);
        $this->assertSame('SP', $cadastro->endereco->uf);

        // O CNPJ sai da URL so com digitos, como o CEP.
        Http::assertSent(fn ($requisicao): bool => str_contains($requisicao->url(), self::CNPJ));
    }

    /**
     * A Receita guarda o tipo do logradouro em campo separado, entao o
     * logradouro do Carrefour e "TUCUNARE" com "AVENIDA" ao lado. Juntar os dois
     * e o que faz o campo da tela ficar igual ao do cartao CNPJ.
     */
    public function test_o_tipo_do_logradouro_vem_junto_do_logradouro(): void
    {
        $this->responderCom(CadastroDaReceita::corpo());

        $this->assertSame(
            'AVENIDA TUCUNARE',
            app(BuscarCadastroPeloCnpj::class)->executar(self::CNPJ)->endereco->logradouro,
        );
    }

    public function test_cadastro_sem_tipo_de_logradouro_nao_ganha_espaco_na_frente(): void
    {
        $this->responderCom(CadastroDaReceita::corpo(['descricao_tipo_de_logradouro' => '']));

        $this->assertSame(
            'TUCUNARE',
            app(BuscarCadastroPeloCnpj::class)->executar(self::CNPJ)->endereco->logradouro,
        );
    }

    /**
     * A resposta traz dois codigos de municipio. O que liga o cadastro a cidade
     * daqui, e ao provedor de NFS-e, e o do IBGE: Barueri e 3505708, e nao os
     * 6213 do codigo da propria Receita.
     */
    public function test_o_municipio_vem_do_codigo_do_ibge_e_nao_do_codigo_da_receita(): void
    {
        $this->responderCom(CadastroDaReceita::corpo());

        $cadastro = app(BuscarCadastroPeloCnpj::class)->executar(self::CNPJ);

        $this->assertSame('3505708', (string) $cadastro->endereco->municipio);
    }

    /**
     * Cadastro que a Receita nao considera regular preenche do mesmo jeito, mas
     * a tela precisa poder avisar: a nota sairia em nome dele.
     */
    public function test_situacao_fora_de_ativa_chega_a_tela(): void
    {
        $this->responderCom(CadastroDaReceita::corpo(['descricao_situacao_cadastral' => 'BAIXADA']));

        $cadastro = app(BuscarCadastroPeloCnpj::class)->executar(self::CNPJ);

        $this->assertFalse($cadastro->estaAtiva());
        $this->assertSame('BAIXADA', $cadastro->situacao);
    }

    /**
     * Documento conferido antes de sair. CNPJ com digito verificador errado
     * voltaria como "nao encontrado", que manda procurar o erro no lugar errado.
     */
    public function test_cnpj_invalido_nem_chega_a_sair(): void
    {
        Http::fake();

        try {
            app(BuscarCadastroPeloCnpj::class)->executar('45543915000182');
            $this->fail('A consulta saiu com um CNPJ invalido.');
        } catch (RuntimeException $falha) {
            $this->assertSame('Informe um CNPJ válido para consultar.', $falha->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * O campo do tomador aceita CPF, e nao ha cadastro publico de pessoa fisica
     * para consultar. A mensagem diz isso, em vez de "nao encontrado".
     */
    public function test_cpf_nao_tem_o_que_consultar(): void
    {
        Http::fake();

        try {
            app(BuscarCadastroPeloCnpj::class)->executar('529.982.247-25');
            $this->fail('A consulta saiu com um CPF.');
        } catch (RuntimeException $falha) {
            $this->assertSame('A consulta é pelo CNPJ. Não há cadastro público de CPF.', $falha->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_cnpj_inexistente_vira_mensagem(): void
    {
        $this->responderCom(['message' => 'CNPJ não encontrado'], 404);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CNPJ 45543915000181 não encontrado na Receita.');

        app(BuscarCadastroPeloCnpj::class)->executar(self::CNPJ);
    }

    /**
     * O servico limita requisicoes por minuto. Dizer "nao encontrado" nesse caso
     * mandaria conferir um CNPJ que esta certo.
     */
    public function test_limite_do_servico_tem_mensagem_propria(): void
    {
        $this->responderCom([], 429);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('atingiu o limite do serviço');

        app(BuscarCadastroPeloCnpj::class)->executar(self::CNPJ);
    }

    public function test_servico_fora_do_ar_vira_mensagem(): void
    {
        Http::fake(fn () => throw new ConnectionException('sem rota para o host'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A consulta de CNPJ não respondeu: sem rota para o host');

        app(BuscarCadastroPeloCnpj::class)->executar(self::CNPJ);
    }

    /**
     * O mesmo CNPJ no mesmo dia nao sai duas vezes: o cadastro da Receita nao
     * muda no intervalo de um preenchimento, e o servico limita requisicoes.
     */
    public function test_o_mesmo_cnpj_nao_e_perguntado_duas_vezes(): void
    {
        $this->responderCom(CadastroDaReceita::corpo());

        $primeiro = app(BuscarCadastroPeloCnpj::class)->executar('45.543.915/0001-81');
        $segundo = app(BuscarCadastroPeloCnpj::class)->executar(self::CNPJ);

        Http::assertSentCount(1);
        $this->assertEquals($primeiro, $segundo, 'o cadastro remontado do cache tem que ser o mesmo');
    }

    /**
     * O que vai para o cache e o corpo da resposta, e nao o objeto pronto.
     * `unserialize()` nao dispara autoload, e o acerto de cache e o caminho em
     * que a classe do DTO ainda nao foi carregada no processo: o objeto voltava
     * como `__PHP_Incomplete_Class` e estourava no tipo de retorno, com o
     * formulario mostrando "Cadastro nao preenchido".
     */
    public function test_o_cache_guarda_o_corpo_da_resposta_e_nao_o_objeto(): void
    {
        $this->responderCom(CadastroDaReceita::corpo());

        app(BuscarCadastroPeloCnpj::class)->executar(self::CNPJ);

        $this->assertIsArray(Cache::get('cnpj:brasilapi:'.self::CNPJ));
    }

    /**
     * @param  array<string, mixed>  $corpo
     */
    private function responderCom(array $corpo, int $status = 200): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response($corpo, $status)]);
    }
}
