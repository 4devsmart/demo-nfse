<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Municipios\ProvedorPrevisto;
use App\Domain\ValueObjects\CodigoIbge;
use App\Filament\Resources\Notas\Pages\CreateNota;
use App\Filament\Resources\Notas\Pages\EditNota;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Respostas\MunicipioAtendido;
use App\Models\Cidade;
use App\Models\ClassificacaoTributaria;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\IndicadorDeOperacao;
use App\Models\Nota;
use App\Models\User;
use Database\Factories\ItemDaNbsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Apoio\GatewayFiscalFalso;
use Tests\TestCase;

/**
 * O `cLocalidadeIncid` e o local da operacao do IBS/CBS (LC 214/2025, art. 11).
 * Giss 2.04, Ginfes, Saatri e ISSNatal o leem dentro do RPS, e sem ele o GISS
 * grava `<cLocalidadeIncid>0000000</cLocalidadeIncid>`.
 *
 * A regra geral e o domicilio do tomador, e nao o municipio do emitente, que e
 * a regra do ISS. Por isso a tela sugere o tomador e deixa trocar.
 */
class LocalidadeDeIncidenciaTest extends TestCase
{
    use RefreshDatabase;

    private GatewayFiscalFalso $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new GatewayFiscalFalso;
        $this->app->instance(GatewayFiscal::class, $this->gateway);
        $this->actingAs(User::factory()->create());
    }

    public function test_provedor_que_le_o_campo_no_rps_sugere_o_municipio_do_tomador(): void
    {
        $this->atendidoPeloGiss();
        Empresa::factory()->create(['cidade_id' => $this->guarulhos()->getKey()]);
        $tomador = Cliente::factory()->create(['cidade_id' => $this->saoPaulo()->getKey()]);

        Livewire::test(CreateNota::class)
            ->fillForm(['tem_ibs_cbs' => true, 'cliente_id' => $tomador->getKey()])
            ->assertFormFieldVisible('cidade_incidencia_ibs_cbs_id')
            ->assertFormSet(['cidade_incidencia_ibs_cbs_id' => $this->saoPaulo()->getKey()]);
    }

    /**
     * No Padrao Nacional quem calcula o local e a Sefin. Sugestao guardada num
     * campo escondido apareceria na revisao como se fosse na DPS.
     */
    public function test_no_padrao_nacional_o_campo_nao_aparece_nem_recebe_sugestao(): void
    {
        Empresa::factory()->create();
        $tomador = Cliente::factory()->create(['cidade_id' => $this->saoPaulo()->getKey()]);

        Livewire::test(CreateNota::class)
            ->fillForm(['tem_ibs_cbs' => true, 'cliente_id' => $tomador->getKey()])
            ->assertFormFieldHidden('cidade_incidencia_ibs_cbs_id')
            ->assertFormSet(['cidade_incidencia_ibs_cbs_id' => null]);
    }

    public function test_trocar_de_tomador_troca_a_sugestao(): void
    {
        $this->atendidoPeloGiss();
        Empresa::factory()->create(['cidade_id' => $this->guarulhos()->getKey()]);
        $paulistano = Cliente::factory()->create(['cidade_id' => $this->saoPaulo()->getKey()]);
        $guarulhense = Cliente::factory()->create(['cidade_id' => $this->guarulhos()->getKey()]);

        Livewire::test(CreateNota::class)
            ->fillForm(['tem_ibs_cbs' => true, 'cliente_id' => $paulistano->getKey()])
            ->fillForm(['cliente_id' => $guarulhense->getKey()])
            ->assertFormSet(['cidade_incidencia_ibs_cbs_id' => $this->guarulhos()->getKey()]);
    }

    /**
     * Obra, servico presencial e evento puxam o local para onde a operacao
     * acontece. Quem emite sabe disso, a sugestao nao: escolha feita a mao fica.
     */
    public function test_municipio_escolhido_a_mao_sobrevive_a_troca_de_tomador(): void
    {
        $this->atendidoPeloGiss();
        Empresa::factory()->create(['cidade_id' => $this->guarulhos()->getKey()]);
        $paulistano = Cliente::factory()->create(['cidade_id' => $this->saoPaulo()->getKey()]);
        $outro = Cliente::factory()->create(['cidade_id' => $this->saoPaulo()->getKey()]);
        $ondeFicaAObra = Cidade::factory()->create(['nome' => 'Campinas', 'uf' => 'SP', 'codigo_ibge' => '3509502']);

        Livewire::test(CreateNota::class)
            ->fillForm(['tem_ibs_cbs' => true, 'cliente_id' => $paulistano->getKey()])
            ->fillForm(['cidade_incidencia_ibs_cbs_id' => $ondeFicaAObra->getKey()])
            ->fillForm(['cliente_id' => $outro->getKey()])
            ->assertFormSet(['cidade_incidencia_ibs_cbs_id' => $ondeFicaAObra->getKey()]);
    }

    /**
     * Nota gravada antes do campo existir abre com a sugestao: sem ela, o campo
     * obrigatorio apareceria vazio numa nota que ja estava pronta.
     */
    public function test_nota_gravada_sem_o_campo_abre_com_a_sugestao(): void
    {
        $this->atendidoPeloGiss();
        $nota = Nota::factory()->create([
            'empresa_id' => Empresa::factory()->create(['cidade_id' => $this->guarulhos()->getKey()])->getKey(),
            'cliente_id' => Cliente::factory()->create(['cidade_id' => $this->saoPaulo()->getKey()])->getKey(),
            'cst_ibs_cbs' => '000',
            'classificacao_tributaria' => '000001',
            'indicador_de_operacao' => '020201',
        ]);

        Livewire::test(EditNota::class, ['record' => $nota->getKey()])
            ->assertFormFieldVisible('cidade_incidencia_ibs_cbs_id')
            ->assertFormSet(['cidade_incidencia_ibs_cbs_id' => $this->saoPaulo()->getKey()]);
    }

    /**
     * Escolhida a mao para um emitente GISS, a localidade continuava gravada
     * depois da troca para um emitente Tinus: o campo escondido nao gravava o
     * vazio, e ela ia na DPS de um provedor que, recebendo o campo, anexa ao
     * RPS o grupo da NFS-e inteiro.
     */
    public function test_emitente_cujo_provedor_nao_le_o_campo_apaga_a_localidade_gravada(): void
    {
        $this->atendidoPeloGiss();
        $nota = $this->notaComLocalidadeEscolhidaAMao();
        $emitenteNoTinus = $this->emitenteQueClassificaIbsCbs($this->saoPaulo());

        $tela = Livewire::test(EditNota::class, ['record' => $nota->getKey()])
            ->assertFormSet(['cidade_incidencia_ibs_cbs_id' => $nota->cidade_incidencia_ibs_cbs_id]);

        $this->gateway->responderMunicipioCom(
            new MunicipioAtendido(CodigoIbge::deSeteDigitos('3550308'), 'Tinus', 'abrasf', true),
        );

        $tela->fillForm(['empresa_id' => $emitenteNoTinus->getKey()])
            ->assertFormFieldHidden('cidade_incidencia_ibs_cbs_id')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($nota->refresh()->cidade_incidencia_ibs_cbs_id);
    }

    /**
     * Sem a API nao se sabe o provedor. Apagar nesse caso tiraria da DPS do
     * GISS o campo que ele exige, por causa de uma queda de rede.
     */
    public function test_api_fora_do_ar_nao_apaga_a_localidade_gravada(): void
    {
        $this->gateway->falharNoMunicipioCom(new ConnectionException('sem rota para o host'));
        $nota = $this->notaComLocalidadeEscolhidaAMao();
        $escolhida = $nota->cidade_incidencia_ibs_cbs_id;

        Livewire::test(EditNota::class, ['record' => $nota->getKey()])
            ->assertFormSet(['cidade_incidencia_ibs_cbs_id' => $escolhida])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($escolhida, $nota->refresh()->cidade_incidencia_ibs_cbs_id);
    }

    #[DataProvider('provedores')]
    public function test_so_os_provedores_que_gravam_o_campo_no_rps_o_exigem(string $provedor, string $layout, bool $exige): void
    {
        $previsto = ProvedorPrevisto::de(new MunicipioAtendido(CodigoIbge::deSeteDigitos('3518800'), $provedor, $layout, true));

        $this->assertSame($exige, $previsto->exigeLocalidadeDeIncidencia());
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function provedores(): array
    {
        return [
            'Giss' => ['Giss', 'abrasf', true],
            'Ginfes' => ['Ginfes', 'abrasf', true],
            'Saatri' => ['Saatri', 'abrasf', true],
            'ISSNatal' => ['ISSNatal', 'abrasf', true],
            'Tinus, que anexaria o grupo da NFS-e inteiro' => ['Tinus', 'abrasf', false],
            'WebISS, que só o tem na NFS-e devolvida' => ['WebISS', 'abrasf', false],
            'Padrão Nacional' => ['PadraoNacional', 'padrao_nacional', false],
        ];
    }

    public function test_api_fora_do_ar_nao_sabe_o_provedor(): void
    {
        $this->assertFalse(ProvedorPrevisto::naoConsultado()->exigeLocalidadeDeIncidencia());
    }

    private function atendidoPeloGiss(): void
    {
        $this->gateway->responderMunicipioCom(
            new MunicipioAtendido(CodigoIbge::deSeteDigitos('3518800'), 'Giss', 'abrasf', true),
        );
    }

    /**
     * Salvar pela tela valida o grupo de IBS/CBS inteiro, e os codigos e a NBS
     * precisam existir nas tabelas oficiais.
     */
    private function notaComLocalidadeEscolhidaAMao(): Nota
    {
        ClassificacaoTributaria::factory()->create(['codigo' => '000001', 'cst' => '000']);
        IndicadorDeOperacao::factory()->create(['codigo' => '020201']);
        $emitente = $this->emitenteQueClassificaIbsCbs($this->guarulhos());

        return Nota::factory()->create([
            'empresa_id' => $emitente->getKey(),
            'cliente_id' => Cliente::factory()->create(['cidade_id' => $this->saoPaulo()->getKey()])->getKey(),
            'cst_ibs_cbs' => $emitente->cst_ibs_cbs_padrao,
            'classificacao_tributaria' => $emitente->classificacao_tributaria_padrao,
            'indicador_de_operacao' => $emitente->indicador_de_operacao_padrao,
            'nbs' => $emitente->nbs_padrao,
            'cidade_incidencia_ibs_cbs_id' => Cidade::factory()->create(['nome' => 'Campinas', 'uf' => 'SP', 'codigo_ibge' => '3509502'])->getKey(),
        ]);
    }

    private function emitenteQueClassificaIbsCbs(Cidade $cidade): Empresa
    {
        return Empresa::factory()->create([
            'cidade_id' => $cidade->getKey(),
            'cst_ibs_cbs_padrao' => '000',
            'classificacao_tributaria_padrao' => '000001',
            'indicador_de_operacao_padrao' => '020201',
            'nbs_padrao' => ItemDaNbsFactory::suporteEmInformatica()->nbs,
        ]);
    }

    private function guarulhos(): Cidade
    {
        return Cidade::query()->firstOrCreate(['codigo_ibge' => '3518800'], ['nome' => 'Guarulhos', 'uf' => 'SP']);
    }

    private function saoPaulo(): Cidade
    {
        return Cidade::query()->firstOrCreate(['codigo_ibge' => '3550308'], ['nome' => 'São Paulo', 'uf' => 'SP']);
    }
}
