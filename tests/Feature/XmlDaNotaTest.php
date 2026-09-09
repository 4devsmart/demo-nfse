<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\StatusNota;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class XmlDaNotaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_baixa_o_xml_da_dps_enviada(): void
    {
        $nota = Nota::factory()->comDpsGerada()->create();

        $resposta = $this->get(route('notas.xml', ['nota' => $nota, 'documento' => 'dps']));

        $resposta->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=utf-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="dps-'.$nota->id_dps.'.xml"');

        $this->assertSame('<DPS/>', $resposta->getContent());
    }

    public function test_baixa_o_xml_da_nfse_autorizada(): void
    {
        $nota = Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'status' => StatusNota::Autorizada,
            'chave' => str_repeat('3', 50),
            'xml_autorizado' => base64_encode('<NFSe/>'),
        ]);

        $this->get(route('notas.xml', ['nota' => $nota, 'documento' => 'nfse']))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="nfse-'.str_repeat('3', 50).'.xml"')
            ->assertSee('<NFSe/>', escape: false);
    }

    /**
     * O nome do arquivo identifica o documento na pasta de quem baixou, e a
     * chave e o identificador que a prefeitura reconhece, o numero da NFS-e so
     * entra quando ela nao existe. Sem os dois, sobra a serie e o numero da DPS,
     * que sao nossos.
     */
    public function test_o_nome_do_arquivo_usa_o_melhor_identificador_disponivel(): void
    {
        $semChave = Nota::factory()->create([
            'status' => StatusNota::Autorizada,
            'numero_nfse' => '202600000001',
            'chave' => null,
            'xml_autorizado' => base64_encode('<NFSe/>'),
        ]);

        $this->get(route('notas.xml', ['nota' => $semChave, 'documento' => 'nfse']))
            ->assertHeader('Content-Disposition', 'attachment; filename="nfse-202600000001.xml"');

        $semIdDps = Nota::factory()->create([
            'serie' => 'A1',
            'numero' => 42,
            'xml_dps' => base64_encode('<DPS/>'),
        ]);

        $this->get(route('notas.xml', ['nota' => $semIdDps, 'documento' => 'dps']))
            ->assertHeader('Content-Disposition', 'attachment; filename="dps-dps-A1-42.xml"');
    }

    public function test_sem_o_documento_devolve_404_com_o_motivo(): void
    {
        $nota = Nota::factory()->create();

        $this->get(route('notas.xml', ['nota' => $nota, 'documento' => 'dps']))->assertNotFound();
        $this->get(route('notas.xml', ['nota' => $nota, 'documento' => 'nfse']))->assertNotFound();
    }

    public function test_documento_desconhecido_nao_existe(): void
    {
        $nota = Nota::factory()->comDpsGerada()->create();

        $this->get("/admin/notas/{$nota->getKey()}/xml/qualquer")->assertNotFound();
    }
}
