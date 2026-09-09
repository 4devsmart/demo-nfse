<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\Ambiente;
use App\Domain\ValueObjects\CodigoIbge;
use App\Domain\ValueObjects\DocumentoFederal;
use App\Fiscal\Certificado\CertificadoDigital;
use App\Fiscal\Excecoes\CodigoDeFalha;
use App\Fiscal\Excecoes\DesfechoIndeterminado;
use App\Fiscal\Excecoes\FalhaFiscal;
use App\Fiscal\Http\WrapperFiscal;
use App\Fiscal\Pedidos\ConsultaPorRps;
use App\Fiscal\Pedidos\ContextoDoProvedor;
use App\Fiscal\Pedidos\MotivoDoCancelamento;
use App\Fiscal\Pedidos\NotaSubstituida;
use App\Fiscal\Pedidos\PayloadDps;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as ClienteHttp;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WrapperFiscalTest extends TestCase
{
    private const URL = 'http://fiscal-api:8080';

    public function test_gerar_dps_devolve_o_identificador_e_o_provedor(): void
    {
        Http::fake([
            self::URL.'/v1/nfse/xml' => Http::response([
                'id_dps' => 'DPS123',
                'xml_b64' => base64_encode('<DPS/>'),
                'layout' => 'padrao_nacional',
                'provedor' => 'PadraoNacional',
                'validacao' => ['ok' => true, 'suportada' => false],
            ]),
        ]);

        $gerada = $this->wrapper()->gerarDps(new PayloadDps(['infDPS' => []]));

        $this->assertSame('DPS123', $gerada->idDps);
        $this->assertSame('PadraoNacional', $gerada->provedor);
        $this->assertSame('<DPS/>', $gerada->xml());
    }

    public function test_a_chamada_leva_o_bearer_do_token(): void
    {
        Http::fake([self::URL.'/v1/nfse/xml' => Http::response(['id_dps' => 'DPS1'])]);

        $this->wrapper()->gerarDps(new PayloadDps([]));

        Http::assertSent(fn ($requisicao): bool => $requisicao->hasHeader('Authorization', 'Bearer token-de-teste'));
    }

    public function test_erro_da_api_vira_falha_com_o_codigo_preservado(): void
    {
        Http::fake([
            self::URL.'/v1/nfse/xml' => Http::response([
                'erro' => ['codigo' => 'provedor_nao_suportado', 'mensagem' => 'município sem provedor'],
            ], 422),
        ]);

        try {
            $this->wrapper()->gerarDps(new PayloadDps([]));
            $this->fail('A falha precisa chegar tipada.');
        } catch (FalhaFiscal $falha) {
            $this->assertSame('provedor_nao_suportado', $falha->codigo);
            $this->assertFalse($falha->ehSeguroRepetir());
        }
    }

    /**
     * Token errado é a falha de configuração mais provável de todas, e ela não
     * é silêncio: a API responde 401 com envelope. O corpo abaixo é o que ela
     * devolve, palavra por palavra.
     *
     * Antes de `nao_autorizado` existir no enum, isto virava `falha_desconhecida`
     * e a página do fluxo não sabia explicar o erro mais comum que ela ia ver.
     */
    public function test_token_errado_vira_falha_com_codigo_proprio(): void
    {
        Http::fake([
            self::URL.'/v1/nfse/xml' => Http::response([
                'erro' => ['codigo' => 'nao_autorizado', 'mensagem' => 'token ausente ou inválido'],
            ], 401),
        ]);

        try {
            $this->wrapper()->gerarDps(new PayloadDps([]));
            $this->fail('A recusa por token precisa chegar tipada.');
        } catch (FalhaFiscal $falha) {
            $this->assertSame(CodigoDeFalha::NaoAutorizado->value, $falha->codigo);
            $this->assertStringContainsString('FISCAL_API_TOKEN', CodigoDeFalha::NaoAutorizado->oQueFazer());
            $this->assertNotInstanceOf(DesfechoIndeterminado::class, $falha, 'nada chegou ao motor fiscal');
        }
    }

    public function test_502_indeterminado_vira_excecao_propria(): void
    {
        Http::fake([
            self::URL.'/v1/nfse/transmissao' => Http::response([
                'erro' => ['codigo' => 'desfecho_indeterminado', 'mensagem' => 'pode ter sido transmitida'],
            ], 502),
        ]);

        $this->expectException(DesfechoIndeterminado::class);

        $this->wrapper()->transmitirDps($this->contexto(), base64_encode('<DPS/>'));
    }

    public function test_rejeicao_do_provedor_volta_como_resposta_e_nao_como_erro(): void
    {
        Http::fake([
            self::URL.'/v1/nfse/transmissao' => Http::response([
                'status' => 'rejeitado',
                'erros' => [['codigo' => 'E1', 'descricao' => 'IM inválida']],
            ], 422),
        ]);

        $resposta = $this->wrapper()->transmitirDps($this->contexto(), base64_encode('<DPS/>'));

        $this->assertFalse($resposta->foiAutorizada());
        $this->assertCount(1, $resposta->erros);
    }

    public function test_a_transmissao_leva_municipio_emitente_e_certificado(): void
    {
        Http::fake([self::URL.'/v1/nfse/transmissao' => Http::response(['status' => 'autorizado'])]);

        $this->wrapper()->transmitirDps($this->contexto(), 'XML==');

        Http::assertSent(function ($requisicao): bool {
            $corpo = $requisicao->data();

            return $corpo['xml_b64'] === 'XML=='
                && $corpo['municipio'] === '3304557'
                && $corpo['ambiente'] === 'homologacao'
                && $corpo['emitente']['cnpj'] === '19131243000197'
                && $corpo['certificado']['pfx_b64'] === base64_encode('pfx');
        });
    }

    public function test_api_fora_do_ar_vira_falha_repetivel(): void
    {
        Http::fake(fn () => throw new ConnectionException('connection refused'));

        try {
            $this->wrapper()->municipio(CodigoIbge::deSeteDigitos('3304557'));
            $this->fail('Deveria falhar.');
        } catch (FalhaFiscal $falha) {
            $this->assertSame('api_inacessivel', $falha->codigo);
            $this->assertTrue($falha->ehSeguroRepetir());
        }
    }

    /**
     * O silencio de uma rota que grava documento nao autoriza repetir. O
     * `ConnectionException` do cliente HTTP cobre tanto "a conexao nem abriu"
     * quanto "estourou o tempo esperando a resposta", e o segundo acontece
     * DEPOIS de o corpo sair, a prefeitura pode ter recebido a DPS.
     */
    public function test_timeout_na_transmissao_vira_desfecho_indeterminado(): void
    {
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out after 120001 milliseconds'
        ));

        try {
            $this->wrapper()->transmitirDps($this->contexto(), base64_encode('<DPS/>'));
            $this->fail('Deveria falhar.');
        } catch (FalhaFiscal $falha) {
            $this->assertInstanceOf(DesfechoIndeterminado::class, $falha);
            $this->assertFalse($falha->ehSeguroRepetir(), 'Repetir duplicaria documento fiscal.');
        }
    }

    /**
     * O silêncio não é só o da conexão. A API pode responder, e responder um
     * 5xx que não diz nada: um 502 do proxy, um corpo HTML, um corpo vazio.
     * Numa rota que grava documento fiscal isso vale o mesmo que o timeout: o
     * erro pode ter sido antes de a prefeitura receber, ou depois.
     *
     * Que a API emite corpo fora do envelope está verificado: `/v1/rota-que-nao-existe`
     * responde `404 page not found` em texto puro.
     *
     * O 500 está aqui pela borda: a regra é `>= 500`, e sem ele um `> 500` passaria
     * despercebido, deixando de fora o erro genérico mais comum.
     */
    #[DataProvider('respostasIlegiveisDeServidor')]
    public function test_5xx_ilegivel_em_rota_que_grava_vira_desfecho_indeterminado(int $status, string $corpo): void
    {
        Http::fake([self::URL.'/v1/nfse/transmissao' => Http::response($corpo, $status)]);

        try {
            $this->wrapper()->transmitirDps($this->contexto(), base64_encode('<DPS/>'));
            $this->fail("Deveria falhar em {$status}.");
        } catch (FalhaFiscal $falha) {
            $this->assertInstanceOf(DesfechoIndeterminado::class, $falha);
            $this->assertFalse($falha->ehSeguroRepetir(), 'Repetir duplicaria documento fiscal.');
            $this->assertStringContainsString((string) $status, $falha->getMessage());
        }
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function respostasIlegiveisDeServidor(): array
    {
        return [
            '500 com corpo vazio' => [500, ''],
            '502 do proxy, em HTML' => [502, '<html>bad gateway</html>'],
            '504 do proxy, em HTML' => [504, '<html>gateway timeout</html>'],
        ];
    }

    /**
     * O contrário do teste acima, e é o que impede a regra de virar "todo 5xx é
     * dúvida": quando a API diz o código, quem manda é o código. `regras_de_negocio`
     * afirma que nada foi transmitido, e ler isso como dúvida mandaria consultar
     * uma nota que com certeza não existe.
     */
    public function test_5xx_com_codigo_conhecido_continua_valendo_pelo_codigo(): void
    {
        Http::fake([
            self::URL.'/v1/nfse/transmissao' => Http::response([
                'erro' => ['codigo' => 'regras_de_negocio', 'mensagem' => 'a biblioteca reprovou o documento'],
            ], 500),
        ]);

        try {
            $this->wrapper()->transmitirDps($this->contexto(), base64_encode('<DPS/>'));
            $this->fail('Deveria falhar.');
        } catch (FalhaFiscal $falha) {
            $this->assertNotInstanceOf(DesfechoIndeterminado::class, $falha);
            $this->assertSame(CodigoDeFalha::RegrasDeNegocio->value, $falha->codigo);
        }
    }

    /**
     * 4xx é recusa na porta: a chamada não chegou a executar, então não há
     * dúvida sobre o documento. Só o 5xx é ambíguo.
     */
    public function test_4xx_ilegivel_em_rota_que_grava_nao_vira_duvida(): void
    {
        Http::fake([self::URL.'/v1/nfse/transmissao' => Http::response('404 page not found', 404)]);

        try {
            $this->wrapper()->transmitirDps($this->contexto(), base64_encode('<DPS/>'));
            $this->fail('Deveria falhar.');
        } catch (FalhaFiscal $falha) {
            $this->assertNotInstanceOf(DesfechoIndeterminado::class, $falha);
        }
    }

    /**
     * E consultar não grava nada: o mesmo 5xx ilegível continua sendo falha
     * comum, como já é o silêncio da conexão.
     */
    public function test_5xx_ilegivel_em_consulta_continua_falha_comum(): void
    {
        Http::fake([self::URL.'/v1/nfse/consulta-dps' => Http::response('<html>bad gateway</html>', 502)]);

        try {
            $this->wrapper()->consultarDps($this->contexto(), 'DPS123');
            $this->fail('Deveria falhar.');
        } catch (FalhaFiscal $falha) {
            $this->assertNotInstanceOf(DesfechoIndeterminado::class, $falha);
        }
    }

    public function test_cancelamento_e_substituicao_tambem_ficam_indeterminados_sem_resposta(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $chamadas = [
            'cancelamento' => fn () => $this->wrapper()->cancelarNota(
                $this->contexto(),
                str_repeat('3', 50),
                MotivoDoCancelamento::descrito('Valor lançado errado na emissão'),
            ),
            'substituicao' => fn () => $this->wrapper()->substituirNota(
                $this->contexto(),
                new PayloadDps(['infDPS' => []]),
                NotaSubstituida::identificadaPor('202600000001', '1'),
                MotivoDoCancelamento::descrito('Valor lançado errado na emissão'),
            ),
        ];

        foreach ($chamadas as $rota => $chamada) {
            try {
                $chamada();
                $this->fail("O silêncio em {$rota} não pode virar falha repetível.");
            } catch (DesfechoIndeterminado $falha) {
                $this->assertFalse($falha->ehSeguroRepetir(), $rota);
            }
        }
    }

    /**
     * Cancelamento e substituicao tambem voltam 422 com o corpo do documento
     * quando o provedor recusa. E recusa, nao erro de protocolo: sem aceitar o
     * 422 a Action nunca chega a gravar as mensagens do fisco na nota.
     */
    public function test_recusa_de_evento_volta_como_resposta_e_nao_como_erro(): void
    {
        Http::fake([
            self::URL.'/v1/nfse/eventos/cancelamento' => Http::response([
                'tipo' => 'cancelamento',
                'status' => 'rejeitado',
                'mensagens' => [['codigo' => 'E260', 'descricao' => 'Prazo expirado']],
            ], 422),
            self::URL.'/v1/nfse/eventos/substituicao' => Http::response([
                'tipo' => 'substituicao',
                'status' => 'rejeitado',
                'mensagens' => [['codigo' => 'E9', 'descricao' => 'Operação não suportada']],
            ], 422),
        ]);

        $cancelamento = $this->wrapper()->cancelarNota(
            $this->contexto(),
            str_repeat('3', 50),
            MotivoDoCancelamento::descrito('Valor lançado errado na emissão'),
        );

        $substituicao = $this->wrapper()->substituirNota(
            $this->contexto(),
            new PayloadDps(['infDPS' => []]),
            NotaSubstituida::identificadaPor('202600000001', '1'),
            MotivoDoCancelamento::descrito('Valor lançado errado na emissão'),
        );

        $this->assertFalse($cancelamento->foiConcluido());
        $this->assertSame('E260 Prazo expirado', $cancelamento->mensagens->emLinhas());
        $this->assertFalse($substituicao->foiConcluido());
        $this->assertSame('E9 Operação não suportada', $substituicao->mensagens->emLinhas());
    }

    /**
     * O contraponto: consultar nao grava nada, entao o mesmo silencio continua
     * significando "nada saiu, pode repetir".
     */
    public function test_consulta_sem_resposta_continua_repetivel(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        try {
            $this->wrapper()->consultarDps($this->contexto(), 'DPS123');
            $this->fail('Deveria falhar.');
        } catch (FalhaFiscal $falha) {
            $this->assertNotInstanceOf(DesfechoIndeterminado::class, $falha);
            $this->assertTrue($falha->ehSeguroRepetir());
        }
    }

    /**
     * Codigo que nao esta no enum e codigo que nao se conhece, e o que nao se
     * conhece nunca autoriza repetir.
     */
    public function test_codigo_desconhecido_nao_autoriza_repetir(): void
    {
        Http::fake([
            self::URL.'/v1/nfse/xml' => Http::response([
                'erro' => ['codigo' => 'algo_que_a_api_inventou', 'mensagem' => 'ninguém sabe'],
            ], 500),
        ]);

        try {
            $this->wrapper()->gerarDps(new PayloadDps([]));
            $this->fail('Deveria falhar.');
        } catch (FalhaFiscal $falha) {
            $this->assertFalse($falha->ehSeguroRepetir());
        }
    }

    /**
     * A resposta da transmissao e lida DEPOIS de o documento existir no
     * provedor. Se o parse levantar, a Action nunca grava o desfecho e a nota
     * fica "DPS gerada" com a NFS-e autorizada do outro lado, que e o convite
     * para reenviar. Nenhum campo fora do formato pode derrubar a leitura.
     */
    public function test_resposta_de_transmissao_fora_do_formato_nao_derruba_a_leitura(): void
    {
        Http::fake([
            self::URL.'/v1/nfse/transmissao' => Http::response([
                'status' => 'autorizado',
                'numero' => '202600000001',
                'chave' => str_repeat('3', 50),
                'erros' => ['uma string solta onde era para vir objeto'],
                'alertas' => 'nem lista isto é',
                'protocolo' => ['inesperadamente', 'um array'],
            ]),
        ]);

        $resposta = $this->wrapper()->transmitirDps($this->contexto(), base64_encode('<DPS/>'));

        $this->assertTrue($resposta->foiAutorizada());
        $this->assertSame('202600000001', $resposta->numero);
        $this->assertSame('', $resposta->protocolo, 'O que não dá para ler vira vazio, não exceção.');
        $this->assertCount(1, $resposta->erros);
        $this->assertSame('uma string solta onde era para vir objeto', $resposta->erros->emLinhas());
        $this->assertCount(0, $resposta->alertas);
    }

    /**
     * O cancelamento e o pedido que apaga uma NFS-e: o corpo e conferido campo
     * a campo, para que trocar a chave pelo motivo nao passe despercebido.
     */
    public function test_o_cancelamento_leva_a_chave_o_motivo_e_o_contexto(): void
    {
        Http::fake([self::URL.'/v1/nfse/eventos/cancelamento' => Http::response(['status' => 'concluido'])]);

        $this->wrapper()->cancelarNota(
            $this->contexto(),
            str_repeat('3', 50),
            MotivoDoCancelamento::descrito('Valor lançado errado na emissão'),
        );

        Http::assertSent(function ($requisicao): bool {
            $corpo = $requisicao->data();

            return $corpo['chave'] === str_repeat('3', 50)
                && $corpo['evento'] === ['codigo' => '1', 'motivo' => 'Valor lançado errado na emissão']
                && $corpo['municipio'] === '3304557'
                && $corpo['certificado']['pfx_b64'] === base64_encode('pfx');
        });
    }

    /**
     * Rejeicao vem em 422 com o corpo do documento; recusa antes de transmitir
     * vem em 422 com envelope de erro. O mesmo status, dois significados, e so
     * a presenca de `erro` os separa.
     */
    public function test_422_com_envelope_de_erro_e_falha_e_nao_resposta(): void
    {
        Http::fake([
            self::URL.'/v1/nfse/transmissao' => Http::response([
                'erro' => ['codigo' => 'campo_obrigatorio', 'mensagem' => 'faltou a inscrição municipal'],
            ], 422),
        ]);

        try {
            $this->wrapper()->transmitirDps($this->contexto(), base64_encode('<DPS/>'));
            $this->fail('Recusa antes de transmitir é falha, não desfecho.');
        } catch (FalhaFiscal $falha) {
            $this->assertSame('campo_obrigatorio', $falha->codigo);
        }
    }

    public function test_a_consulta_da_dps_leva_a_chave_e_o_contexto(): void
    {
        Http::fake([self::URL.'/v1/nfse/consulta-dps' => Http::response(['codigo' => 0, 'resposta' => 'ok'])]);

        $this->wrapper()->consultarDps($this->contexto(), 'DPS123');

        Http::assertSent(fn ($requisicao): bool => $requisicao->data()['chave'] === 'DPS123'
            && $requisicao->data()['ambiente'] === 'homologacao');
    }

    public function test_a_consulta_pela_chave_pergunta_o_estado_da_nota(): void
    {
        Http::fake([self::URL.'/v1/nfse/consulta' => Http::response([
            'codigo' => 0,
            'resposta' => 'nota autorizada',
            'xml_b64' => base64_encode('<NFSe/>'),
        ])]);

        $resposta = $this->wrapper()->consultarNota($this->contexto(), str_repeat('3', 50));

        $this->assertTrue($resposta->foiSucesso());
        $this->assertSame(base64_encode('<NFSe/>'), $resposta->xmlEmBase64);
        Http::assertSent(fn ($requisicao): bool => $requisicao->data()['chave'] === str_repeat('3', 50)
            && $requisicao->data()['municipio'] === '3304557');
    }

    /**
     * A fila DF-e chega crua: o lote e um JSON no meio do texto da biblioteca,
     * entre `XmlRetorno=` e a primeira secao `[ArquivoN]`, e cada documento vem
     * em base64 com gzip por dentro. E o unico lugar onde o documento do evento
     * existe, entao ler o formato errado e ficar sem ele.
     */
    public function test_a_distribuicao_le_o_lote_no_meio_do_retorno_cru(): void
    {
        $evento = '<evento versao="1.01"><infEvento/></evento>';

        $lote = json_encode([
            'StatusProcessamento' => 'DOCUMENTOS_LOCALIZADOS',
            'LoteDFe' => [[
                'NSU' => 21,
                'ChaveAcesso' => str_repeat('3', 50),
                'TipoDocumento' => 'EVENTO',
                'ArquivoXml' => base64_encode((string) gzencode($evento)),
            ]],
        ]);

        Http::fake([self::URL.'/v1/nfse/distribuicao' => Http::response([
            'codigo' => 0,
            'resposta' => "[ConsultarDFe]\nNSU=20\nXmlEnvio=/DFe/20\nXmlRetorno={$lote}\n[Arquivo1]\nNomeArquivo=\n",
        ])]);

        $recebido = $this->wrapper()->distribuirDocumentos($this->contexto(), 20);

        $this->assertCount(1, $recebido->documentos);
        $this->assertSame($evento, $recebido->documentos[0]->xml);
        $this->assertTrue($recebido->documentos[0]->ehEventoDa(str_repeat('3', 50)));
        $this->assertSame(21, $recebido->ultimoNsu(20));

        Http::assertSent(fn ($requisicao): bool => $requisicao->data()['nsu'] === 20
            && $requisicao->data()['municipio'] === '3304557');
    }

    /**
     * Fim da fila: o ADN responde `NENHUM_DOCUMENTO_LOCALIZADO` com lote vazio,
     * e e assim que o passeio sabe parar.
     */
    public function test_fila_no_fim_volta_vazia_e_nao_erro(): void
    {
        Http::fake([self::URL.'/v1/nfse/distribuicao' => Http::response([
            'codigo' => 0,
            'resposta' => '[ConsultarDFe]'."\n".'XmlRetorno={"StatusProcessamento":"NENHUM_DOCUMENTO_LOCALIZADO","LoteDFe":[]}',
        ])]);

        $this->assertTrue($this->wrapper()->distribuirDocumentos($this->contexto(), 99)->vazio());
    }

    /**
     * O RPS e o mesmo par serie/numero que este sistema controla, e ele viaja
     * junto do contexto: sem os dois no corpo, o provedor nao sabe de quem e a
     * numeracao pela qual esta sendo perguntado.
     */
    public function test_a_consulta_por_rps_leva_o_par_serie_numero_e_o_contexto(): void
    {
        Http::fake([self::URL.'/v1/nfse/consultas/rps' => Http::response(['codigo' => 0, 'resposta' => 'achou'])]);

        $this->wrapper()->consultarPorRps($this->contexto(), ConsultaPorRps::sobreORps('42', 'A1'));

        Http::assertSent(function ($requisicao): bool {
            $corpo = $requisicao->data();

            return $corpo['numero'] === '42'
                && $corpo['serie'] === 'A1'
                && $corpo['tipo'] === '1'
                && $corpo['emitente']['cnpj'] === '19131243000197';
        });
    }

    /**
     * A substituicao leva as duas notas numa chamada so: a DPS nova e a
     * identificacao da antiga, dentro do mesmo grupo `evento`, que e onde o
     * motivo tambem entra.
     */
    public function test_a_substituicao_leva_as_duas_notas_no_mesmo_evento(): void
    {
        Http::fake([self::URL.'/v1/nfse/eventos/substituicao' => Http::response([
            'tipo' => 'substituicao',
            'status' => 'concluido',
            'chave' => str_repeat('9', 50),
        ])]);

        $evento = $this->wrapper()->substituirNota(
            $this->contexto(),
            new PayloadDps(['infDPS' => ['nDPS' => '43']]),
            NotaSubstituida::identificadaPor('202600000001', '1', 'ABC123'),
            MotivoDoCancelamento::descrito('Valor do serviço errado'),
        );

        $this->assertTrue($evento->foiConcluido());

        Http::assertSent(function ($requisicao): bool {
            $corpo = $requisicao->data();
            $evento = $corpo['evento'];

            return $evento['dps']['infDPS']['nDPS'] === '43'
                && $evento['substituida']['numero'] === '202600000001'
                && $evento['substituida']['codigo_verificacao'] === 'ABC123'
                && $evento['motivo'] === 'Valor do serviço errado'
                // O contexto viaja fora do `evento`, no mesmo nivel da rota.
                && $corpo['municipio'] === '3304557'
                && $corpo['emitente']['cnpj'] === '19131243000197';
        });
    }

    /**
     * O DANFSE e render local a partir do XML autorizado: nao leva certificado
     * e nao fala com a prefeitura. Quem decide o desenho e o municipio.
     */
    public function test_o_danfse_sai_do_xml_autorizado_e_do_municipio(): void
    {
        Http::fake([self::URL.'/v1/nfse/pdf' => Http::response(['pdf_b64' => base64_encode('%PDF-1.4')])]);

        $danfse = $this->wrapper()->gerarDanfse(base64_encode('<NFSe/>'), CodigoIbge::deSeteDigitos('3304557'));

        $this->assertSame('%PDF-1.4', $danfse->conteudo());
        Http::assertSent(fn ($requisicao): bool => $requisicao->data()['municipio'] === '3304557'
            && $requisicao->data()['xml_b64'] === base64_encode('<NFSe/>'));
    }

    /**
     * A consulta de municipio e GET e nao leva certificado: e como se descobre,
     * antes de montar qualquer coisa, se vale a pena tentar.
     */
    public function test_a_consulta_de_municipio_e_um_get_sem_certificado(): void
    {
        Http::fake([self::URL.'/v1/nfse/municipios/3304557' => Http::response([
            'codigo' => '3304557',
            'provedor' => 'PadraoNacional',
            'layout' => 'padrao_nacional',
            'suportado' => true,
        ])]);

        $municipio = $this->wrapper()->municipio(CodigoIbge::deSeteDigitos('3304557'));

        $this->assertTrue($municipio->suportado);
        $this->assertSame('PadraoNacional (padrao_nacional)', $municipio->descricao());
        Http::assertSent(fn ($requisicao): bool => $requisicao->method() === 'GET' && $requisicao->data() === []);
    }

    public function test_a_identificacao_junta_ping_e_capacidades_em_duas_chamadas(): void
    {
        Http::fake([
            self::URL.'/v1/ping' => Http::response(['versao' => ['commit_curto' => 'abc1234', 'build' => 'b1']]),
            self::URL.'/v1/capacidades' => Http::response(['base' => '/v1', 'modulos' => ['nfse' => ['xml']]]),
        ]);

        $identificacao = $this->wrapper()->identificacao();

        $this->assertSame('abc1234', $identificacao->commit);
        $this->assertSame(['nfse' => ['xml']], $identificacao->modulos);
        Http::assertSentCount(2);
    }

    /**
     * A URL base vem da configuracao e pode chegar com barra no fim, e a rota
     * nao pode virar `//v1/nfse/xml`, que e outro caminho.
     *
     * Quem apara e o `PendingRequest::send()` do Laravel, que faz
     * `rtrim($this->baseUrl, '/')` antes de concatenar sempre que o caminho e
     * relativo, e todas as rotas desta classe sao. O `WrapperFiscal` nao apara
     * de novo: este teste existe para o dia em que o framework mudar isso.
     *
     * A assercao e negativa porque o cliente HTTP grava duas entradas para uma
     * requisicao a `//v1/...`: a pedida e a normalizada. Conferir igualdade com
     * a forma certa passaria mesmo com a barra dobrada.
     */
    public function test_a_barra_sobrando_na_url_base_nao_dobra_na_rota(): void
    {
        Http::fake([self::URL.'/v1/nfse/xml' => Http::response(['id_dps' => 'DPS1'])]);

        new WrapperFiscal(app(ClienteHttp::class), self::URL.'/', 'token-de-teste', 5)
            ->gerarDps(new PayloadDps([]));

        Http::assertNotSent(fn ($requisicao): bool => str_contains($requisicao->url(), ':8080//'));
    }

    /**
     * Corpo que nao e JSON nao pode derrubar a leitura: a resposta pode ser de
     * uma chamada que ja gravou documento no provedor.
     */
    public function test_corpo_que_nao_e_json_vira_resposta_vazia_e_nao_erro(): void
    {
        Http::fake([self::URL.'/v1/nfse/xml' => Http::response('<html>gateway</html>', 200)]);

        $gerada = $this->wrapper()->gerarDps(new PayloadDps([]));

        $this->assertSame('', $gerada->idDps);
        $this->assertSame('', $gerada->xml());
    }

    private function wrapper(): WrapperFiscal
    {
        return new WrapperFiscal(app(ClienteHttp::class), self::URL, 'token-de-teste', 5);
    }

    private function contexto(): ContextoDoProvedor
    {
        return ContextoDoProvedor::novo(
            municipio: CodigoIbge::deSeteDigitos('3304557'),
            ambiente: Ambiente::Homologacao,
            cnpjDoEmitente: DocumentoFederal::deCpfOuCnpj('19131243000197'),
            inscricaoMunicipal: '1234567',
            razaoSocial: 'Demo LTDA',
            certificado: CertificadoDigital::deConteudoBinario('pfx', 'senha'),
        );
    }
}
