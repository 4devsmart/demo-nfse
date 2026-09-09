<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\StatusNota;
use App\Models\Empresa;
use App\Models\Nota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O que impede cada operacao, e em que ordem.
 *
 * A ordem decide o que quem opera faz a seguir, quando dois impedimentos valem
 * ao mesmo tempo. Dizer "gere a DPS primeiro" a quem nao tem certificado manda
 * a pessoa para o lugar errado: ela gera a DPS e esbarra no mesmo muro.
 */
class ImpedimentosDaNotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_estado_da_nota_vem_antes_da_falta_de_certificado(): void
    {
        $nota = Nota::factory()->create(['status' => StatusNota::Cancelada]);

        $this->assertStringContainsString('não é transmitida de novo', (string) $nota->impedimentos()->paraEmitir());
    }

    public function test_a_falta_de_certificado_vem_antes_da_falta_de_dps(): void
    {
        $nota = Nota::factory()->create(['status' => StatusNota::Rascunho]);

        $this->assertStringContainsString(
            'certificado A1',
            (string) $nota->impedimentos()->paraTransmitir(),
            'Sem certificado, gerar a DPS não desbloqueia nada.',
        );
    }

    public function test_com_certificado_o_que_falta_e_a_dps(): void
    {
        $nota = Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'status' => StatusNota::Rascunho,
        ]);

        $this->assertStringContainsString('Gere a DPS primeiro', (string) $nota->impedimentos()->paraTransmitir());
    }

    public function test_a_falta_de_certificado_vem_antes_da_falta_de_chave(): void
    {
        $nota = Nota::factory()->create(['status' => StatusNota::Autorizada, 'chave' => null]);

        $this->assertStringContainsString('certificado A1', (string) $nota->impedimentos()->paraCancelar());
    }

    public function test_com_certificado_o_que_falta_para_cancelar_e_a_chave(): void
    {
        $nota = Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'status' => StatusNota::Autorizada,
            'chave' => null,
        ]);

        $this->assertStringContainsString('chave de acesso', (string) $nota->impedimentos()->paraCancelar());
    }

    /**
     * Substituir e cancelar mais um requisito: o numero que o provedor atribuiu.
     * O que impede cancelar impede substituir antes, e primeiro.
     */
    public function test_substituir_repete_os_impedimentos_de_cancelar_antes_do_seu(): void
    {
        $semChave = Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'status' => StatusNota::Autorizada,
            'chave' => null,
            'numero_nfse' => null,
        ]);

        $semNumero = Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'status' => StatusNota::Autorizada,
            'chave' => str_repeat('3', 50),
            'numero_nfse' => null,
        ]);

        $this->assertStringContainsString('chave de acesso', (string) $semChave->impedimentos()->paraSubstituir());
        $this->assertStringContainsString('número que o provedor', (string) $semNumero->impedimentos()->paraSubstituir());
    }

    public function test_consultar_a_dps_pede_certificado_antes_do_id_dps(): void
    {
        $semCertificado = Nota::factory()->create(['id_dps' => null]);
        $comCertificado = Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'id_dps' => null,
        ]);

        $this->assertStringContainsString('certificado A1', (string) $semCertificado->impedimentos()->paraConsultarADps());
        $this->assertStringContainsString('id_dps', (string) $comCertificado->impedimentos()->paraConsultarADps());
    }

    public function test_nada_impede_quando_tudo_esta_no_lugar(): void
    {
        $nota = Nota::factory()->comDpsGerada()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
        ]);

        $this->assertNull($nota->impedimentos()->paraTransmitir());
        $this->assertNull($nota->impedimentos()->paraEmitir());
        $this->assertNull($nota->impedimentos()->paraFalarComOProvedor());
    }

    /**
     * Consultar tambem e falar com o provedor, e falar com o provedor e
     * assinar. Ter a chave nao dispensa o certificado: sem ele a consulta nao
     * sai, e dizer que nada impede manda quem opera clicar num botao que vai
     * falhar.
     */
    public function test_o_certificado_e_exigido_mesmo_com_a_chave_em_maos(): void
    {
        $nota = Nota::factory()->create([
            'status' => StatusNota::Autorizada,
            'chave' => str_repeat('3', 50),
        ]);

        $this->assertStringContainsString(
            'certificado A1',
            (string) $nota->impedimentos()->paraConsultarPelaChave(),
        );
    }

    /**
     * O DANFSE nao depende de certificado nem de estado: depende do XML
     * autorizado, que e a unica coisa a partir da qual ele e desenhado.
     */
    public function test_imprimir_so_depende_do_xml_autorizado(): void
    {
        $semXml = Nota::factory()->create(['status' => StatusNota::Autorizada]);
        $comXml = Nota::factory()->create([
            'status' => StatusNota::Autorizada,
            'xml_autorizado' => base64_encode('<NFSe/>'),
        ]);

        $this->assertStringContainsString('XML autorizado', (string) $semXml->impedimentos()->paraImprimir());
        $this->assertNull($comXml->impedimentos()->paraImprimir());
    }

    /**
     * Alterar nao fala com o provedor, entao a falta de certificado nao entra:
     * o que decide e so o estado.
     */
    public function test_alterar_nao_olha_o_certificado(): void
    {
        $rascunho = Nota::factory()->create(['status' => StatusNota::Rascunho]);
        $autorizada = Nota::factory()->create(['status' => StatusNota::Autorizada]);

        $this->assertNull($rascunho->impedimentos()->paraAlterar());
        $this->assertStringContainsString(
            'o que existe é cancelar e emitir outra',
            (string) $autorizada->impedimentos()->paraAlterar(),
        );
    }
}
