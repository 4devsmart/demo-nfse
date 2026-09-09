<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\RetencaoIssqn;
use App\Domain\Enums\StatusNota;
use App\Domain\Enums\TipoSuspensaoDeExigibilidade;
use App\Models\Empresa;
use App\Models\Nota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O que a nota sabe dizer sobre si: qual é o próximo passo, como se chama, e o
 * que ela leva para a DPS.
 *
 * `orientacao()` é a frase em destaque na tela da nota, e ela tem uma ordem
 * própria: quando o próximo passo está bloqueado, o que interessa é o bloqueio,
 * não o passo. Dizer "gere a DPS" a quem não tem certificado manda a pessoa
 * tentar de novo o que não vai funcionar.
 */
class OrientacaoDaNotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_impedimento_vem_antes_do_proximo_passo(): void
    {
        $semCertificado = Nota::factory()->create();

        $this->assertStringContainsString(
            'ainda não tem certificado A1',
            $semCertificado->orientacao(),
        );
    }

    public function test_sem_impedimento_a_orientacao_e_o_proximo_passo(): void
    {
        $nota = Nota::factory()->create(['empresa_id' => Empresa::factory()->comCertificado()]);

        $this->assertSame($nota->status->proximoPasso(), $nota->orientacao());
    }

    /**
     * Numa nota que não vai mais a lugar nenhum, o certificado deixou de ser o
     * assunto: o que a tela precisa dizer é que ela está encerrada.
     */
    public function test_estado_final_orienta_pelo_estado_e_nao_pelo_certificado(): void
    {
        $cancelada = Nota::factory()->create(['status' => StatusNota::Cancelada]);
        $autorizada = Nota::factory()->create(['status' => StatusNota::Autorizada]);

        $this->assertSame(StatusNota::Cancelada->proximoPasso(), $cancelada->orientacao());
        $this->assertSame(StatusNota::Autorizada->proximoPasso(), $autorizada->orientacao());
    }

    /**
     * Quem numera a DPS é este sistema; quem numera a NFS-e é a prefeitura. A
     * identificação diz qual dos dois números existe, confundi-los faz alguém
     * procurar no portal da prefeitura um número que só existe aqui.
     */
    public function test_a_identificacao_troca_a_dps_pela_nfse_quando_o_provedor_numera(): void
    {
        $rascunho = Nota::factory()->create(['serie' => 'A1', 'numero' => 42]);
        $autorizada = Nota::factory()->create([
            'status' => StatusNota::Autorizada,
            'numero_nfse' => '202600000001',
        ]);

        $this->assertSame('DPS A1/42', $rascunho->identificacao());
        $this->assertSame('NFS-e 202600000001', $autorizada->identificacao());
    }

    public function test_as_mensagens_do_provedor_sao_tipadas_mesmo_quando_nao_ha(): void
    {
        $nota = Nota::factory()->create();

        $this->assertCount(0, $nota->mensagensDoProvedor());

        $nota->forceFill(['mensagens' => [['codigo' => 'E001', 'descricao' => 'Falta o CNPJ']]])->save();

        $this->assertSame('E001 Falta o CNPJ', $nota->fresh()?->mensagensDoProvedor()->emLinhas());
    }

    /**
     * Os campos monetários são `decimal` e o cast do Laravel devolve string. É
     * por isso que a nota expõe métodos que devolvem `Dinheiro` e `Aliquota`:
     * fazer conta com a string é como o erro de centavo entra.
     */
    public function test_os_valores_da_nota_saem_tipados(): void
    {
        $nota = Nota::factory()->create(['valor_servico' => 1500.50, 'aliquota_iss' => 2.75]);

        $this->assertSame(1500.50, $nota->valorDoServico()->emReais());
        $this->assertSame(2.75, $nota->aliquotaDoIss()->percentual);
        $this->assertSame(now()->format('m/Y'), $nota->competenciaDoServico()->rotulo());
    }

    public function test_sem_suspensao_nem_beneficio_nem_totais_os_grupos_nao_existem(): void
    {
        $nota = Nota::factory()->create();

        $this->assertNull($nota->exigibilidadeSuspensa());
        $this->assertNull($nota->beneficioMunicipal());
        $this->assertNull($nota->totaisAproximados());
    }

    public function test_a_suspensao_nasce_do_tipo_e_do_processo_gravados(): void
    {
        $nota = Nota::factory()->create([
            'tipo_suspensao' => TipoSuspensaoDeExigibilidade::ProcessoAdministrativo,
            'numero_processo_suspensao' => 'PROC-2026-1',
        ]);

        $suspensao = $nota->exigibilidadeSuspensa();

        $this->assertNotNull($suspensao);
        $this->assertSame(TipoSuspensaoDeExigibilidade::ProcessoAdministrativo, $suspensao->tipo);
        $this->assertSame('PROC-2026-1', $suspensao->numeroDoProcesso);
    }

    /**
     * Benefício concedido sem percentual de redução é benefício de zero por
     * cento, não de um por cento. O percentual reduz a base, então ele é
     * imposto: inventar qualquer número aqui muda o que a prefeitura recebe.
     */
    public function test_beneficio_sem_percentual_reduz_zero(): void
    {
        $nota = Nota::factory()->create(['numero_beneficio_municipal' => 'BM-1']);

        $beneficio = $nota->beneficioMunicipal();

        $this->assertNotNull($beneficio);
        $this->assertSame('BM-1', $beneficio->numero);
        $this->assertSame(0.0, $beneficio->percentualDeReducao->percentual);
    }

    /**
     * Um total sozinho já faz o grupo existir, e cada um vai no seu campo:
     * federal não é estadual, e o XML não corrige quem trocou.
     */
    public function test_cada_total_aproximado_vai_no_seu_campo(): void
    {
        $nota = Nota::factory()->create(['total_tributos_estaduais' => 40]);

        $totais = $nota->totaisAproximados();

        $this->assertNotNull($totais);
        $this->assertSame(
            ['vTotTribFed' => 0.0, 'vTotTribEst' => 40.0, 'vTotTribMun' => 0.0],
            $totais->paraApi(),
        );
    }

    public function test_a_nota_leva_para_a_dps_o_servico_e_os_valores_gravados(): void
    {
        $nota = Nota::factory()->create([
            'codigo_servico' => '140101',
            'cnae' => '9511800',
            'item_lista_servico' => '14.01',
            'descricao_servico' => 'Manutenção',
            'valor_servico' => 1000,
            'aliquota_iss' => 5,
            'retencao_issqn' => RetencaoIssqn::RetidoPeloTomador,
        ]);

        $servico = $nota->servicoPrestado()->paraApi();
        $valores = $nota->valoresDoServico();

        $this->assertSame('140101', $servico['cServ']);
        $this->assertSame('9511800', $servico['codigoCnae']);
        $this->assertSame('14.01', $servico['itemListaServico']);
        $this->assertSame('3304557', $servico['cMunPrestacao']);
        $this->assertSame(50.0, $valores->issqnDevido()->emReais());
        $this->assertSame(950.0, $valores->valorLiquido()->emReais());
    }

    /**
     * A substituta aponta para a original por `substitui_nota_id`; a original
     * chega à substituta pela relação inversa. A tela da nota substituída
     * precisa das duas pontas para mostrar qual documento vale.
     */
    public function test_a_original_e_a_substituta_se_alcancam_pelos_dois_lados(): void
    {
        $original = Nota::factory()->create(['status' => StatusNota::Substituida]);
        $substituta = Nota::factory()->create([
            'empresa_id' => $original->empresa_id,
            'substitui_nota_id' => $original->getKey(),
        ]);

        $this->assertTrue($substituta->is($original->fresh()?->substituta));
        $this->assertTrue($original->is($substituta->fresh()?->substituida));
    }
}
