<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\Empresas\Pages\EditEmpresa;
use App\Fiscal\Traducao\ContextoDaEmpresa;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Login de webservice exigido por provedores fora do Padrão Nacional. Como o
 * certificado, a API não persiste: vai na requisição e morre com ela.
 */
class CredenciaisDaPrefeituraTest extends TestCase
{
    use RefreshDatabase;

    public function test_sem_credenciais_o_grupo_nem_sai_no_corpo(): void
    {
        $empresa = Empresa::factory()->comCertificado()->create();

        $this->assertNull($empresa->credenciaisDaPrefeitura());
        $this->assertArrayNotHasKey('credenciais', app(ContextoDaEmpresa::class)->montar($empresa)->paraApi());
    }

    public function test_com_credenciais_elas_acompanham_toda_chamada(): void
    {
        $empresa = Empresa::factory()->comCertificado()->create([
            'prefeitura_usuario' => 'fulano',
            'prefeitura_senha' => 'segredo',
        ]);

        $corpo = app(ContextoDaEmpresa::class)->montar($empresa)->paraApi();

        $this->assertSame(['usuario' => 'fulano', 'senha' => 'segredo'], $corpo['credenciais']);
    }

    public function test_ficam_cifradas_no_banco(): void
    {
        $empresa = Empresa::factory()->create(['prefeitura_senha' => 'segredo']);

        $bruto = DB::table('empresas')->where('id', $empresa->getKey())->sole();

        $this->assertStringNotContainsString('segredo', (string) $bruto->prefeitura_senha);
        $this->assertSame('segredo', $empresa->refresh()->prefeitura_senha);
    }

    /**
     * As três colunas são `#[Hidden]` no modelo, e `attributesToArray()`, que é
     * de onde o Filament preenche o formulário, as omite. Os campos chegavam
     * vazios à tela e voltavam vazios no save: mexer na razão social apagava o
     * login de webservice, sem erro nenhum.
     *
     * O emitente sem credenciais só para de transmitir na próxima nota, longe da
     * tela que causou o apagamento. É por isso que o teste passa pelo formulário
     * inteiro em vez de conferir só o modelo.
     */
    public function test_editar_o_emitente_pela_tela_nao_apaga_as_credenciais(): void
    {
        $this->actingAs(User::factory()->create());

        $empresa = Empresa::factory()->comCertificado()->create([
            'prefeitura_usuario' => 'fulano',
            'prefeitura_senha' => 'segredo',
            'prefeitura_token' => 'tok-123',
        ]);

        Livewire::test(EditEmpresa::class, ['record' => $empresa->getKey()])
            ->fillForm(['razao_social' => 'Nome Trocado LTDA'])
            ->call('save')
            ->assertHasNoFormErrors();

        $empresa->refresh();

        $this->assertSame('Nome Trocado LTDA', $empresa->razao_social);
        $this->assertSame('fulano', $empresa->prefeitura_usuario);
        $this->assertSame('segredo', $empresa->prefeitura_senha);
        $this->assertSame('tok-123', $empresa->prefeitura_token);
    }
}
