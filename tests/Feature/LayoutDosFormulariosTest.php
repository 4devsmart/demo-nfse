<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\Clientes\Pages\CreateCliente;
use App\Filament\Resources\Clientes\Pages\EditCliente;
use App\Filament\Resources\Empresas\Pages\CreateEmpresa;
use App\Filament\Resources\Empresas\Pages\EditEmpresa;
use App\Filament\Resources\Notas\Pages\EditNota;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\User;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * O container de uma pagina de recurso do Filament vem com DUAS colunas. Uma
 * secao que nao ocupe a largura toda cai ao lado da anterior, e os campos
 * dentro dela ficam com um terco do espaco que deviam ter, rotulo quebrando em
 * duas linhas, select cortando o nome do cliente, CEP mostrando "2003".
 *
 * Este teste existe porque o defeito e invisivel no codigo: cada formulario
 * parece certo isoladamente. So a coluna da raiz denuncia.
 */
class LayoutDosFormulariosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_todo_formulario_empilha_as_secoes(): void
    {
        foreach ($this->formularios() as $nome => $componente) {
            $pagina = $componente->instance();
            assert($pagina instanceof HasSchemas);

            $schema = $pagina->getSchema('form');
            $this->assertNotNull($schema, "O formulário de {$nome} não expõe um schema chamado 'form'.");

            $this->assertSame(
                1,
                $schema->getColumns('lg'),
                "O formulário de {$nome} não fixou a raiz em uma coluna: as seções vão se espremer lado a lado.",
            );
        }
    }

    /**
     * @return array<string, Testable>
     */
    private function formularios(): array
    {
        $empresa = Empresa::factory()->create();
        $cliente = Cliente::factory()->create();
        $nota = Nota::factory()->create();

        return [
            'nova empresa' => Livewire::test(CreateEmpresa::class),
            'edição de empresa' => Livewire::test(EditEmpresa::class, ['record' => $empresa->getKey()]),
            'novo cliente' => Livewire::test(CreateCliente::class),
            'edição de cliente' => Livewire::test(EditCliente::class, ['record' => $cliente->getKey()]),
            'edição de nota' => Livewire::test(EditNota::class, ['record' => $nota->getKey()]),
        ];
    }
}
