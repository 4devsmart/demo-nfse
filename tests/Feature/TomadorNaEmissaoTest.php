<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\TipoPessoa;
use App\Filament\Resources\Clientes\Pages\CreateCliente;
use App\Filament\Resources\Notas\Pages\CreateNota;
use App\Filament\Resources\Notas\Pages\EditNota;
use App\Models\Cliente;
use App\Models\Nota;
use App\Models\User;
use Database\Factories\CidadeFactory;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * O tomador que ainda nao existe, cadastrado sem sair da nota.
 *
 * Quem emite descobre que o tomador falta no meio da digitacao. Mandar essa
 * pessoa para a tela de clientes custa a nota que estava sendo preenchida, e o
 * cadastro que ela faz correndo la e o mesmo que faria aqui.
 */
class TomadorNaEmissaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_o_tomador_cadastrado_no_modal_ja_fica_escolhido_na_nota(): void
    {
        $cidade = CidadeFactory::rio();

        Livewire::test(CreateNota::class)
            ->callAction($this->novoTomador(), [
                'tipo_pessoa' => TipoPessoa::Juridica->value,
                'cpf_cnpj' => '45.543.915/0001-81',
                'razao_social' => 'Comércio Exemplo S.A.',
                'inscricao_municipal' => '7654321',
                'cidade_id' => $cidade->getKey(),
                'cep' => '20031-170',
                'logradouro' => 'Avenida Rio Branco',
                'numero' => '100',
                'bairro' => 'Centro',
            ])
            ->assertHasNoActionErrors()
            ->assertFormSet(fn (array $estado): bool => $estado['cliente_id'] === Cliente::query()->sole()->getKey());

        $tomador = Cliente::query()->sole();

        // A máscara é enfeite de digitação também aqui: o modal desidrata pelos
        // mesmos campos da tela de clientes.
        $this->assertSame('45543915000181', $tomador->cpf_cnpj);
        $this->assertSame('20031170', $tomador->cep);
        $this->assertSame(TipoPessoa::Juridica, $tomador->tipo_pessoa);
    }

    /**
     * O `unique` do documento precisa saber de que tabela ele fala. O seletor
     * guarda `cliente_id` e não declara relacionamento, então sem o model do
     * modal declarado o Filament procuraria o CNPJ na tabela de notas, e o
     * documento repetido só apareceria no índice do banco.
     */
    public function test_documento_repetido_e_recusado_dentro_do_modal(): void
    {
        Cliente::factory()->create(['cpf_cnpj' => '45543915000181']);
        $cidade = CidadeFactory::rio();

        Livewire::test(CreateNota::class)
            ->callAction($this->novoTomador(), [
                'tipo_pessoa' => TipoPessoa::Juridica->value,
                'cpf_cnpj' => '45.543.915/0001-81',
                'razao_social' => 'Outro Comércio',
                'cidade_id' => $cidade->getKey(),
                'cep' => '20031-170',
                'logradouro' => 'Avenida Rio Branco',
                'numero' => '100',
                'bairro' => 'Centro',
            ])
            ->assertHasActionErrors(['cpf_cnpj' => 'unique']);

        $this->assertSame(1, Cliente::query()->count());
    }

    public function test_o_modal_de_edicao_abre_com_o_tomador_da_nota(): void
    {
        $nota = Nota::factory()->create();

        Livewire::test(EditNota::class, ['record' => $nota->getKey()])
            ->mountAction($this->editarTomador())
            ->assertActionDataSet([
                'razao_social' => $nota->cliente->razao_social,
                'cpf_cnpj' => $nota->cliente->documentoFederal()->formatado(),
                'bairro' => $nota->cliente->bairro,
            ]);
    }

    public function test_editar_o_tomador_pelo_modal_grava_e_atualiza_o_rotulo(): void
    {
        $nota = Nota::factory()->create();
        $tomador = $nota->cliente;

        Livewire::test(EditNota::class, ['record' => $nota->getKey()])
            ->callAction($this->editarTomador(), [
                ...$tomador->attributesToArray(),
                'razao_social' => 'Razão Corrigida S.A.',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('Razão Corrigida S.A.', $tomador->refresh()->razao_social);
        $this->assertSame($tomador->getKey(), $nota->refresh()->cliente_id, 'A nota continua apontando para o mesmo tomador.');
    }

    /**
     * Não há o que editar antes de escolher alguém, e o lápis some. É o mesmo
     * critério da lupa do CNPJ: botão que só sabe recusar é ruído na tela.
     */
    public function test_o_lapis_so_aparece_depois_de_escolher_o_tomador(): void
    {
        Livewire::test(CreateNota::class)
            ->assertActionHidden($this->editarTomador())
            ->fillForm(['cliente_id' => Cliente::factory()->create()->getKey()])
            ->assertActionVisible($this->editarTomador());
    }

    /**
     * O cadastro do tomador é alto: em modal centrado ele empurraria a nota
     * para fora da vista. O painel lateral deixa a nota atrás dele.
     */
    public function test_os_dois_modais_abrem_em_painel_lateral(): void
    {
        $tomador = Cliente::factory()->create();

        foreach ([$this->novoTomador(), $this->editarTomador()] as $acao) {
            $pagina = Livewire::test(CreateNota::class)
                ->fillForm(['cliente_id' => $tomador->getKey()])
                ->mountAction($acao)
                ->instance();

            assert($pagina instanceof CreateNota);
            $montada = $pagina->getMountedAction();

            $this->assertNotNull($montada);
            $this->assertTrue($montada->isModalSlideOver(), 'A ação do tomador tem que abrir em painel lateral.');
        }
    }

    /**
     * A coluna de explicação ao lado dos campos é o desenho da tela de
     * cadastro, onde há largura para ela. No painel lateral ela repetia o que o
     * título do modal já diz e empurrava os campos para metade do espaço, a
     * ponto de cortar o CNPJ no meio. O título de cada bloco fica; o texto sai.
     */
    public function test_o_painel_lateral_mostra_os_blocos_sem_a_coluna_de_explicacao(): void
    {
        Livewire::test(CreateNota::class)
            ->mountAction($this->novoTomador())
            ->assertMountedActionModalSee(['Identificação', 'Endereço'])
            ->assertMountedActionModalDontSee('O tomador do serviço: quem recebe a nota.')
            ->assertMountedActionModalDontSee('O município decide o provedor de NFS-e');
    }

    /**
     * A tela de clientes continua com a explicação ao lado: lá ela cabe, e é
     * onde quem cadastra pela primeira vez lê o que cada bloco quer dizer.
     */
    public function test_a_tela_de_clientes_continua_explicando_cada_bloco(): void
    {
        Livewire::test(CreateCliente::class)
            ->assertSee('O tomador do serviço: quem recebe a nota.')
            ->assertSee('O município decide o provedor de NFS-e');
    }

    private function novoTomador(): TestAction
    {
        return TestAction::make('createOption')->schemaComponent('cliente_id');
    }

    private function editarTomador(): TestAction
    {
        return TestAction::make('editOption')->schemaComponent('cliente_id');
    }
}
