<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Enums\Ambiente;
use App\Domain\ValueObjects\CodigoIbge;
use App\Domain\ValueObjects\DocumentoFederal;
use App\Fiscal\Certificado\CertificadoDigital;
use App\Fiscal\Pedidos\ConsultaPorRps;
use App\Fiscal\Pedidos\ContextoDoProvedor;
use App\Fiscal\Pedidos\CredenciaisDaPrefeitura;
use App\Fiscal\Pedidos\MotivoDoCancelamento;
use App\Fiscal\Pedidos\NotaSubstituida;
use App\Fiscal\Pedidos\PayloadDps;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * O que sai daqui para a API, tirando a DPS: identificadores de consulta, o
 * motivo de um evento e o contexto que acompanha toda chamada.
 *
 * O tema comum e a poda. Campo em branco nao pode virar `"serie": ""` no
 * corpo: a API aceita, o provedor rejeita, e o motivo chega como
 * `regras_de_negocio` sem dizer qual campo era.
 *
 * Sobe o Laravel, ao contrario dos demais testes de `tests/Unit`: as mensagens
 * passam por `__()`, e o helper precisa do container.
 */
class PedidosDaApiTest extends TestCase
{
    public function test_a_consulta_por_rps_apara_o_que_foi_digitado(): void
    {
        $consulta = ConsultaPorRps::sobreORps(' 42 ', ' A1 ', ' 1 ', ' XYZ ');

        $this->assertSame([
            'numero' => '42',
            'serie' => 'A1',
            'tipo' => '1',
            'codigo_verificacao' => 'XYZ',
        ], $consulta->paraApi());
    }

    public function test_a_consulta_por_rps_recusa_numero_ou_serie_em_branco(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ConsultaPorRps::sobreORps('  ', '1');
    }

    public function test_a_consulta_por_rps_recusa_serie_em_branco(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ConsultaPorRps::sobreORps('42', '   ');
    }

    public function test_a_consulta_por_rps_omite_o_que_nao_foi_informado(): void
    {
        $this->assertSame(
            ['numero' => '42', 'serie' => '1', 'tipo' => '1'],
            ConsultaPorRps::sobreORps('42', '1')->paraApi(),
        );
    }

    public function test_a_nota_substituida_apara_e_omite_o_que_falta(): void
    {
        $this->assertSame(
            ['numero' => '202600000001', 'serie' => 'A1', 'codigo_verificacao' => 'ABC123'],
            NotaSubstituida::identificadaPor(' 202600000001 ', ' A1 ', ' ABC123 ')->paraApi(),
        );

        $this->assertSame(
            ['numero' => '202600000001', 'serie' => '1'],
            NotaSubstituida::identificadaPor('202600000001', '1')->paraApi(),
        );
    }

    public function test_a_substituicao_recusa_nota_sem_numero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NotaSubstituida::identificadaPor('   ', '1');
    }

    /**
     * O provedor guarda este texto junto do evento, e o Padrao Nacional exige
     * quinze caracteres. Quinze passa; quatorze nao, e sao caracteres, nao
     * bytes: "Não foi presta" tem quatorze letras e quinze bytes.
     */
    public function test_o_motivo_do_cancelamento_conta_caracteres_e_nao_bytes(): void
    {
        $this->assertSame(
            ['codigo' => '1', 'motivo' => 'Erro na emissão'],
            MotivoDoCancelamento::descrito('Erro na emissão')->paraApi(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('O motivo do cancelamento precisa de pelo menos 15 caracteres.');

        MotivoDoCancelamento::descrito('Não foi presta');
    }

    /**
     * Espaco nao conta como motivo: sem aparar, treze caracteres com padding
     * passariam pelo minimo e o provedor receberia um texto que nao explica
     * nada.
     */
    public function test_o_motivo_e_medido_depois_de_aparado(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MotivoDoCancelamento::descrito('      Erro simples      ');
    }

    public function test_o_motivo_aceita_um_codigo_diferente_do_padrao(): void
    {
        $motivo = MotivoDoCancelamento::descrito('  Serviço não prestado  ', '2');

        $this->assertSame('2', $motivo->codigo);
        $this->assertSame('Serviço não prestado', $motivo->descricao);
    }

    /**
     * O grupo de credenciais e tudo ou nada por campo: basta um preenchido para
     * ele sair no corpo. Provedor fora do Padrao Nacional recusa a chamada sem
     * ele, e o erro que volta nao fala de credencial.
     */
    public function test_uma_credencial_preenchida_ja_faz_o_grupo_existir(): void
    {
        $this->assertTrue(new CredenciaisDaPrefeitura()->estaVazia());
        $this->assertFalse(new CredenciaisDaPrefeitura(token: 'abc')->estaVazia());
        $this->assertFalse(new CredenciaisDaPrefeitura(usuario: 'joao')->estaVazia());
        $this->assertFalse(new CredenciaisDaPrefeitura(senha: 'segredo')->estaVazia());

        $this->assertSame(
            ['usuario' => 'joao', 'token' => 'abc'],
            new CredenciaisDaPrefeitura(usuario: 'joao', token: 'abc')->paraApi(),
        );
    }

    public function test_o_contexto_leva_municipio_ambiente_emitente_e_certificado(): void
    {
        $corpo = $this->contexto()->paraApi();

        $this->assertSame('3304557', $corpo['municipio']);
        $this->assertSame('homologacao', $corpo['ambiente']);
        $this->assertSame([
            'cnpj' => '19131243000197',
            'inscricao_municipal' => '1234567',
            'razao_social' => 'Demo LTDA',
        ], $corpo['emitente']);
        $this->assertSame(['pfx_b64' => 'cGZ4', 'senha' => 'segredo'], $corpo['certificado']);
        $this->assertArrayNotHasKey('credenciais', $corpo);
    }

    public function test_o_emitente_sem_inscricao_municipal_nao_leva_o_campo_vazio(): void
    {
        $corpo = ContextoDoProvedor::novo(
            municipio: CodigoIbge::deSeteDigitos('3304557'),
            ambiente: Ambiente::Producao,
            cnpjDoEmitente: DocumentoFederal::deCpfOuCnpj('19131243000197'),
            inscricaoMunicipal: '',
            razaoSocial: 'Demo LTDA',
            certificado: CertificadoDigital::deBase64('cGZ4', 'segredo'),
        )->paraApi();

        $this->assertSame(['cnpj' => '19131243000197', 'razao_social' => 'Demo LTDA'], $corpo['emitente']);
        $this->assertSame('producao', $corpo['ambiente']);
    }

    public function test_as_credenciais_entram_no_contexto_quando_existem(): void
    {
        $corpo = $this->contexto()->com(new CredenciaisDaPrefeitura(usuario: 'joao', senha: 'segredo'))->paraApi();

        $this->assertSame(['usuario' => 'joao', 'senha' => 'segredo'], $corpo['credenciais']);
    }

    /**
     * O JSON da DPS e o que a tela mostra em "Ver JSON da DPS", para ler, nao
     * para transmitir. Barra escapada e acento em `ç` transformam o corpo
     * numa parede que nao ensina nada.
     */
    public function test_o_json_da_dps_sai_legivel(): void
    {
        $json = new PayloadDps(['infDPS' => ['xDescServ' => 'Manutenção de ar-condicionado', 'cServ' => '14/01']])->emJson();

        $this->assertStringContainsString('Manutenção', $json);
        $this->assertStringContainsString('14/01', $json);
        $this->assertStringContainsString("\n", $json);
    }

    private function contexto(): ContextoDoProvedor
    {
        return ContextoDoProvedor::novo(
            municipio: CodigoIbge::deSeteDigitos('3304557'),
            ambiente: Ambiente::Homologacao,
            cnpjDoEmitente: DocumentoFederal::deCpfOuCnpj('19131243000197'),
            inscricaoMunicipal: '1234567',
            razaoSocial: 'Demo LTDA',
            certificado: CertificadoDigital::deBase64('cGZ4', 'segredo'),
        );
    }
}
