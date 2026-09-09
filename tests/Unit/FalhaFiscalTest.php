<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Fiscal\Excecoes\CodigoDeFalha;
use App\Fiscal\Excecoes\DesfechoIndeterminado;
use App\Fiscal\Excecoes\FalhaFiscal;
use RuntimeException;
use Tests\TestCase;

/**
 * O envelope de erro da API, virando tipo.
 *
 * O contrato manda tratar pelo `codigo`, nunca pela mensagem, entao e o
 * `codigo` que precisa sobreviver intacto ao caminho todo, inclusive quando o
 * corpo vem faltando pedaco.
 *
 * Sobe o Laravel, ao contrario dos demais testes de `tests/Unit`: as mensagens
 * passam por `__()`, e o helper precisa do container.
 */
class FalhaFiscalTest extends TestCase
{
    public function test_preserva_codigo_mensagem_detalhes_e_status(): void
    {
        $falha = FalhaFiscal::doCorpoDaResposta([
            'erro' => [
                'codigo' => 'campo_obrigatorio',
                'mensagem' => 'Faltou o CNPJ do prestador.',
                'detalhes' => ['campo' => 'prest.CNPJ'],
            ],
        ], 422);

        $this->assertSame('campo_obrigatorio', $falha->codigo);
        $this->assertSame('Faltou o CNPJ do prestador.', $falha->getMessage());
        $this->assertSame(['campo' => 'prest.CNPJ'], $falha->detalhes);
        $this->assertSame(422, $falha->statusHttp);
    }

    /**
     * Corpo sem envelope acontece: proxy no meio, 502 de gateway, HTML de erro.
     * O que nao pode acontecer e a leitura derrubar antes de a Action gravar o
     * desfecho da nota.
     */
    public function test_corpo_sem_envelope_vira_falha_desconhecida(): void
    {
        $falha = FalhaFiscal::doCorpoDaResposta([], 500);

        $this->assertSame('falha_desconhecida', $falha->codigo);
        $this->assertSame('A API fiscal recusou a chamada.', $falha->getMessage());
        $this->assertSame([], $falha->detalhes);
        $this->assertFalse($falha->ehSeguroRepetir());
    }

    /**
     * O contrato diz que `detalhes` e objeto e `codigo` e string. Quando vem
     * outra coisa, embrulhar e melhor que estourar: a alternativa e perder a
     * resposta inteira de uma chamada que ja gravou documento no provedor.
     */
    public function test_campos_fora_do_contrato_sao_normalizados(): void
    {
        $falha = FalhaFiscal::doCorpoDaResposta([
            'erro' => ['codigo' => 429, 'mensagem' => 17, 'detalhes' => 'espere 30s'],
        ], 429);

        $this->assertSame('429', $falha->codigo);
        $this->assertSame('17', $falha->getMessage());
        $this->assertSame(['espere 30s'], $falha->detalhes);
    }

    public function test_envelope_que_nao_e_objeto_nao_derruba_a_leitura(): void
    {
        $falha = FalhaFiscal::doCorpoDaResposta(['erro' => 'deu ruim'], 500);

        $this->assertSame('falha_desconhecida', $falha->codigo);
    }

    /**
     * O 502 da API tem tipo proprio porque ele e o unico que NAO autoriza
     * repetir. Quem captura `DesfechoIndeterminado` nao pode depender de
     * comparar string de codigo.
     */
    public function test_desfecho_indeterminado_vira_a_subclasse(): void
    {
        $falha = FalhaFiscal::doCorpoDaResposta([
            'erro' => ['codigo' => DesfechoIndeterminado::CODIGO, 'mensagem' => 'sem resposta do provedor'],
        ], 502);

        $this->assertInstanceOf(DesfechoIndeterminado::class, $falha);
        $this->assertFalse($falha->ehSeguroRepetir());
    }

    /**
     * Sem status HTTP nenhum, `statusHttp` e zero, e nao 1 nem -1. E o valor
     * que a tela le para saber que a chamada nem chegou a ter resposta.
     */
    public function test_falha_sem_resposta_nao_tem_status_http(): void
    {
        $falha = FalhaFiscal::semResposta('Connection timed out');

        $this->assertSame(CodigoDeFalha::ApiInacessivel->value, $falha->codigo);
        $this->assertSame(0, $falha->statusHttp);
        $this->assertSame(0, $falha->getCode());
        $this->assertStringContainsString('Connection timed out', $falha->getMessage());
        $this->assertTrue($falha->ehSeguroRepetir(), 'Nada saiu: repetir e seguro.');
    }

    /**
     * O mesmo silencio, depois de a chamada sair, quer dizer outra coisa: o
     * documento PODE existir no provedor, e repetir duplicaria a nota.
     */
    public function test_o_silencio_depois_de_enviar_e_indeterminado(): void
    {
        $falha = FalhaFiscal::semRespostaDepoisDeEnviar('cURL error 28');

        $this->assertInstanceOf(DesfechoIndeterminado::class, $falha);
        $this->assertSame(CodigoDeFalha::DesfechoIndeterminado->value, $falha->codigo);
        $this->assertSame(0, $falha->statusHttp);
        $this->assertFalse($falha->ehSeguroRepetir());
        $this->assertStringContainsString('cURL error 28', $falha->getMessage());
    }

    public function test_a_falha_construida_a_mao_nasce_sem_status_e_sem_codigo_de_excecao(): void
    {
        $falha = new FalhaFiscal('regras_de_negocio', 'A biblioteca reprovou o documento.');

        $this->assertInstanceOf(RuntimeException::class, $falha);
        $this->assertSame(0, $falha->statusHttp);
        $this->assertSame(0, $falha->getCode());
        $this->assertSame([], $falha->detalhes);
    }

    public function test_o_resumo_junta_codigo_e_mensagem(): void
    {
        $falha = new FalhaFiscal('lib_indisponivel', 'O motor fiscal não respondeu.');

        $this->assertSame('[lib_indisponivel] O motor fiscal não respondeu.', $falha->resumo());
    }

    public function test_codigo_que_a_api_nao_documenta_nao_autoriza_repetir(): void
    {
        $this->assertFalse(new FalhaFiscal('inventado', 'seja lá o que for')->ehSeguroRepetir());
    }
}
