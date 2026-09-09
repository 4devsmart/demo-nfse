<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Fiscal\Excecoes\FalhaFiscal;
use App\Fiscal\Respostas\Danfse;
use App\Fiscal\Respostas\DpsGerada;
use App\Fiscal\Respostas\EventoRegistrado;
use App\Fiscal\Respostas\IdentificacaoDaApi;
use App\Fiscal\Respostas\LeitorDaResposta;
use App\Fiscal\Respostas\Mensagem;
use App\Fiscal\Respostas\Mensagens;
use App\Fiscal\Respostas\MunicipioAtendido;
use App\Fiscal\Respostas\NotaTransmitida;
use App\Fiscal\Respostas\RespostaCrua;
use Tests\TestCase;

/**
 * A leitura do que a API devolve.
 *
 * Tudo aqui gira em torno da mesma assimetria: transmissao, cancelamento e
 * substituicao sao lidos DEPOIS de o documento ja existir no provedor. Um
 * `(string)` sobre um array vira `ErrorException`, a excecao sobe antes de a
 * Action gravar o desfecho, e a nota fica "DPS gerada" com a NFS-e autorizada
 * do outro lado. Por isso campo fora do contrato vira vazio, e nao erro.
 *
 * Sobe o Laravel, ao contrario dos demais testes de `tests/Unit`: as mensagens
 * passam por `__()`, e o helper precisa do container.
 */
class RespostasDaApiTest extends TestCase
{
    public function test_o_leitor_apara_texto_e_cai_no_padrao_quando_nao_da_para_ler(): void
    {
        $leitor = new LeitorDaResposta(['numero' => '  202600000001  ', 'erros' => ['a']]);

        $this->assertSame('202600000001', $leitor->texto('numero'));
        $this->assertSame('', $leitor->texto('ausente'));
        $this->assertSame('erro', $leitor->texto('status', 'erro'));
        $this->assertSame('', $leitor->texto('erros'), 'Array onde era para vir texto vira vazio, nao warning.');
    }

    public function test_o_leitor_converte_numero_e_respeita_o_padrao(): void
    {
        $leitor = new LeitorDaResposta(['codigo' => '0', 'outro' => 'x']);

        $this->assertSame(0, $leitor->inteiro('codigo', -1));
        $this->assertSame(-1, $leitor->inteiro('outro', -1));
        $this->assertSame(-1, $leitor->inteiro('ausente', -1));
    }

    public function test_o_leitor_le_logico_lista_objeto_e_grupo_aninhado(): void
    {
        $leitor = new LeitorDaResposta([
            'suportado' => 1,
            'mensagens' => [['codigo' => 'A1']],
            'modulos' => ['nfse' => ['/v1/nfse/xml']],
            'versao' => ['commit_curto' => 'abc1234'],
        ]);

        $this->assertTrue($leitor->logico('suportado'));
        $this->assertFalse($leitor->logico('ausente'));
        $this->assertTrue($leitor->logico('ausente', true));
        $this->assertSame([['codigo' => 'A1']], $leitor->lista('mensagens'));
        $this->assertSame([], $leitor->lista('versao_inexistente'));
        $this->assertSame(['nfse' => ['/v1/nfse/xml']], $leitor->objeto('modulos'));
        $this->assertSame('abc1234', $leitor->dentroDe('versao')->texto('commit_curto'));
    }

    /**
     * O contrato manda `{"codigo","descricao"}`. Uma string solta no meio da
     * lista nao pode derrubar a leitura, a resposta pode trazer nota
     * autorizada, e perde-la e pior que perder o formato.
     *
     * A lista posicional entra aqui pelo mesmo motivo: lida so pelas chaves,
     * ela virava mensagem vazia, e a rejeicao aparecia na tela sem motivo.
     */
    public function test_mensagem_fora_do_contrato_preserva_o_que_o_provedor_disse(): void
    {
        $this->assertSame('E001', Mensagem::comoVeio(['codigo' => 'E001', 'descricao' => 'x'])->codigo);
        $this->assertSame('', Mensagem::comoVeio('  string solta  ')->codigo);
        $this->assertSame('string solta', Mensagem::comoVeio('  string solta  ')->descricao);
    }

    /**
     * Ha provedor que manda `["E123", "Aliquota divergente"]`, sem as chaves.
     * O primeiro escalar e o codigo, o resto e a descricao; com um item so,
     * ele e a descricao, porque texto sem codigo ainda explica.
     */
    public function test_mensagem_em_lista_posicional_vira_codigo_e_descricao(): void
    {
        $porPosicao = Mensagem::comoVeio(['E123', 'Alíquota divergente']);

        $this->assertSame('E123', $porPosicao->codigo);
        $this->assertSame('Alíquota divergente', $porPosicao->descricao);

        $this->assertSame('e lista', Mensagem::comoVeio(['isto', 'e', 'lista'])->descricao);
        $this->assertSame('', Mensagem::comoVeio(['so isto'])->codigo);
        $this->assertSame('so isto', Mensagem::comoVeio(['so isto'])->descricao);
        $this->assertSame('', Mensagem::comoVeio([])->descricao);
    }

    public function test_a_mensagem_se_le_como_codigo_mais_descricao(): void
    {
        $this->assertSame('E001 Falta o CNPJ', (string) new Mensagem('E001', 'Falta o CNPJ'));
        $this->assertSame('Falta o CNPJ', (string) new Mensagem('', 'Falta o CNPJ'));
    }

    /**
     * A lista chega indexada pelo provedor, e nem sempre por inteiro em
     * sequencia. O que sai daqui e sempre lista, e o que o `mensagens` do
     * banco guarda, com cast `array`.
     */
    public function test_as_mensagens_saem_como_lista_na_ordem_em_que_vieram(): void
    {
        $mensagens = Mensagens::daLista([
            'primeiro' => ['codigo' => 'A1', 'descricao' => 'um'],
            'segundo' => ['codigo' => 'B2', 'descricao' => 'dois'],
        ]);

        $this->assertCount(2, $mensagens);
        $this->assertSame([
            ['codigo' => 'A1', 'descricao' => 'um'],
            ['codigo' => 'B2', 'descricao' => 'dois'],
        ], $mensagens->paraArray());
        $this->assertSame("A1 um\nB2 dois", $mensagens->emLinhas());
    }

    public function test_a_colecao_vazia_nao_e_nula(): void
    {
        $vazia = Mensagens::vazia();

        $this->assertCount(0, $vazia);
        $this->assertSame([], $vazia->paraArray());
        $this->assertSame('', $vazia->emLinhas());
        $this->assertSame([], iterator_to_array($vazia));
    }

    /**
     * Consulta sem `codigo` no corpo nao e sucesso. O zero e o "deu certo" da
     * biblioteca, entao o padrao tem que ser um valor que ninguem confunde
     * com ele.
     */
    public function test_consulta_sem_codigo_nao_passa_por_sucesso(): void
    {
        $resposta = RespostaCrua::doCorpoDaResposta([]);

        $this->assertSame(-1, $resposta->codigo);
        $this->assertFalse($resposta->foiSucesso());
        $this->assertSame('', $resposta->xmlEmBase64);
    }

    public function test_consulta_com_codigo_zero_e_sucesso_e_traz_o_xml(): void
    {
        $resposta = RespostaCrua::doCorpoDaResposta([
            'codigo' => 0,
            'resposta' => 'nota encontrada',
            'xml_b64' => base64_encode('<NFSe/>'),
        ]);

        $this->assertTrue($resposta->foiSucesso());
        $this->assertSame(base64_encode('<NFSe/>'), $resposta->xmlEmBase64);
    }

    public function test_a_dps_gerada_traz_o_identificador_e_o_xml_decodificado(): void
    {
        $gerada = DpsGerada::doCorpoDaResposta([
            'id_dps' => 'DPS3304557...',
            'xml_b64' => base64_encode('<DPS/>'),
            'layout' => 'padrao_nacional',
            'provedor' => 'PadraoNacional',
        ]);

        $this->assertSame('DPS3304557...', $gerada->idDps);
        $this->assertSame('<DPS/>', $gerada->xml());
    }

    /**
     * Sem `status`, a transmissao NAO e autorizada. O padrao pende para o lado
     * seguro: dizer "autorizado" sem o provedor ter dito seria inventar
     * documento fiscal.
     */
    public function test_transmissao_sem_status_nao_e_autorizada(): void
    {
        $resposta = NotaTransmitida::doCorpoDaResposta([]);

        $this->assertSame('erro', $resposta->status);
        $this->assertFalse($resposta->foiAutorizada());
        $this->assertCount(0, $resposta->erros);
        $this->assertCount(0, $resposta->alertas);
        $this->assertSame('', $resposta->xmlEmBase64);
    }

    public function test_transmissao_autorizada_traz_numero_chave_e_xml(): void
    {
        $resposta = NotaTransmitida::doCorpoDaResposta([
            'status' => 'autorizado',
            'numero' => '202600000001',
            'chave' => str_repeat('3', 50),
            'codigo_verificacao' => 'ABC123',
            'protocolo' => 'PROTO-1',
            'situacao' => 'autorizada',
            'xml_b64' => base64_encode('<NFSe/>'),
            'alertas' => ['prazo de envio no limite'],
        ]);

        $this->assertTrue($resposta->foiAutorizada());
        $this->assertSame('202600000001', $resposta->numero);
        $this->assertSame('ABC123', $resposta->codigoDeVerificacao);
        $this->assertSame('autorizada', $resposta->situacao);
        $this->assertSame(base64_encode('<NFSe/>'), $resposta->xmlEmBase64);
        $this->assertSame('prazo de envio no limite', $resposta->alertas->emLinhas());
    }

    /**
     * Os tres nomes de "deu certo" que a API usa conforme o evento. Qualquer
     * outro e recusa, inclusive o silencio.
     */
    public function test_o_evento_so_esta_concluido_com_um_dos_tres_status(): void
    {
        foreach (['concluido', 'autorizado', 'registrado'] as $status) {
            $this->assertTrue($this->evento($status)->foiConcluido(), $status);
        }

        $this->assertFalse($this->evento('rejeitado')->foiConcluido());
        $this->assertFalse(EventoRegistrado::doCorpoDaResposta([])->foiConcluido());
    }

    public function test_o_evento_traz_chave_protocolo_e_mensagens(): void
    {
        $evento = EventoRegistrado::doCorpoDaResposta([
            'tipo' => 'cancelamento',
            'status' => 'concluido',
            'chave' => str_repeat('9', 50),
            'protocolo' => 'PROTO-CANC',
            'data_hora' => '2026-09-06T12:00:00-03:00',
            'xml_b64' => base64_encode('<evento/>'),
            'mensagens' => [['codigo' => 'C1', 'descricao' => 'cancelado']],
        ]);

        $this->assertSame('cancelamento', $evento->tipo);
        $this->assertSame('PROTO-CANC', $evento->protocolo);
        $this->assertSame('2026-09-06T12:00:00-03:00', $evento->dataHora);
        $this->assertSame([['codigo' => 'C1', 'descricao' => 'cancelado']], $evento->mensagens->paraArray());
    }

    /**
     * `xml_b64` esta documentado como o XML do evento, mas o cancelamento no
     * Padrao Nacional devolve ali a frase "Indice informado nao encontrado".
     * Guardar aquilo daria ao operador um `.xml` com uma frase de erro dentro.
     */
    public function test_o_evento_so_entrega_o_xml_quando_veio_documento(): void
    {
        $comDocumento = EventoRegistrado::doCorpoDaResposta([
            'status' => 'concluido',
            'xml_b64' => base64_encode('<evento/>'),
        ]);

        $this->assertSame(base64_encode('<evento/>'), $comDocumento->documentoDoEvento());

        foreach (['Indice informado não encontrado', '', '   '] as $naoEDocumento) {
            $this->assertNull(
                EventoRegistrado::doCorpoDaResposta([
                    'status' => 'concluido',
                    'xml_b64' => base64_encode($naoEDocumento),
                ])->documentoDoEvento(),
                "O evento aceitou [{$naoEDocumento}] como documento.",
            );
        }

        $this->assertNull(EventoRegistrado::doCorpoDaResposta([])->documentoDoEvento());
    }

    public function test_o_danfse_decodifica_o_pdf(): void
    {
        $danfse = Danfse::doCorpoDaResposta(['pdf_b64' => base64_encode('%PDF-1.4')]);

        $this->assertSame('%PDF-1.4', $danfse->conteudo());
        $this->assertSame('', Danfse::doCorpoDaResposta([])->conteudo());
    }

    /**
     * Sem codigo utilizavel nao ha resposta. Um corpo vazio precisa falhar
     * aqui, e nao virar o municipio "0000000": a tela responderia que ele nao
     * tem provedor conhecido, escondendo a falha.
     */
    public function test_municipio_sem_codigo_utilizavel_vira_falha(): void
    {
        $this->expectException(FalhaFiscal::class);
        $this->expectExceptionMessage('sem um código IBGE utilizável: "".');

        MunicipioAtendido::doCorpoDaResposta(['provedor' => 'PadraoNacional']);
    }

    public function test_o_municipio_atendido_se_descreve_pelo_provedor(): void
    {
        $atendido = MunicipioAtendido::doCorpoDaResposta([
            'codigo' => '3304557',
            'provedor' => 'PadraoNacional',
            'layout' => 'padrao_nacional',
            'suportado' => true,
        ]);

        $this->assertSame('3304557', (string) $atendido->codigo);
        $this->assertSame('PadraoNacional (padrao_nacional)', $atendido->descricao());
    }

    public function test_municipio_sem_provedor_diz_isso_em_vez_de_mostrar_vazio(): void
    {
        $semProvedor = MunicipioAtendido::doCorpoDaResposta(['codigo' => '3304557', 'suportado' => false]);

        $this->assertFalse($semProvedor->suportado);
        $this->assertSame('sem provedor de NFS-e conhecido', $semProvedor->descricao());
    }

    /**
     * `/v1/ping` e `/v1/capacidades` juntos: e assim que se descobre o contrato
     * sem tentar a rota e tomar 404.
     */
    public function test_a_identificacao_junta_ping_e_capacidades(): void
    {
        $identificacao = IdentificacaoDaApi::dasRespostas(
            ['versao' => ['commit_curto' => 'abc1234', 'build' => '2026-09-01']],
            ['base' => '/v2', 'modulos' => ['nfse' => ['/v1/nfse/xml', 2]]],
        );

        $this->assertSame('abc1234', $identificacao->commit);
        $this->assertSame('2026-09-01', $identificacao->build);
        $this->assertSame('/v2', $identificacao->base);
        $this->assertSame(['nfse' => ['/v1/nfse/xml', '2']], $identificacao->modulos);
    }

    /**
     * As rotas de um modulo saem como lista, mesmo quando a API as manda como
     * objeto: a view as percorre com `foreach` e mostrar a chave junto seria
     * ruido, e o contrato nao promete o formato.
     */
    public function test_as_rotas_de_um_modulo_saem_sempre_como_lista(): void
    {
        $identificacao = IdentificacaoDaApi::dasRespostas(
            [],
            ['modulos' => ['nfse' => ['gerar' => '/v1/nfse/xml', 'enviar' => '/v1/nfse/transmissao']]],
        );

        $this->assertSame(['nfse' => ['/v1/nfse/xml', '/v1/nfse/transmissao']], $identificacao->modulos);
    }

    public function test_capacidades_sem_base_assume_a_v1(): void
    {
        $identificacao = IdentificacaoDaApi::dasRespostas([], []);

        $this->assertSame('/v1', $identificacao->base);
        $this->assertSame([], $identificacao->modulos);
        $this->assertSame('', $identificacao->commit);
    }

    private function evento(string $status): EventoRegistrado
    {
        return EventoRegistrado::doCorpoDaResposta(['status' => $status]);
    }
}
