<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Servicos\ImportarCodigosDeTributacaoNacional;
use App\Actions\Servicos\ImportarCorrelacaoNbs;
use App\Actions\Servicos\ImportarListaDeServicos;
use App\Consultas\BuscaDeCodigosDeServico;
use App\Filament\Resources\Notas\Pages\CreateNota;
use App\Models\Empresa;
use App\Models\ItemDaNbs;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * O `cNBS` da DPS. A rejeicao E0322 do Padrao Nacional recusa a nota que declara
 * qualquer informacao de IBS/CBS sem informar um item da NBS, e este projeto
 * nunca enviava o campo.
 *
 * A fonte e o Anexo VIII do RTC, que correlaciona subitem da LC 116, NBS,
 * cClassTrib e cIndOp.
 */
class CorrelacaoNbsTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_arquivo_local_carrega_a_correlacao_do_anexo_oito(): void
    {
        $total = app(ImportarCorrelacaoNbs::class)->executar();

        $this->assertSame(903, $total);
        $this->assertSame(903, ItemDaNbs::query()->count());

        // 676 itens de NBS distintos sobre os 200 subitens da lista.
        $this->assertSame(676, ItemDaNbs::query()->distinct()->count('nbs'));
        $this->assertSame(200, ItemDaNbs::query()->distinct()->count('item_lista_servico'));
    }

    /**
     * O campo so e respondivel porque vem filtrado: sao 676 itens na tabela, e o
     * servico da nota costuma ter menos de cinco.
     */
    public function test_as_opcoes_vem_filtradas_pelo_subitem_do_servico(): void
    {
        $this->carregarTabelas();

        $servicos = app(BuscaDeCodigosDeServico::class);

        $this->assertCount(4, $servicos->nbsParaSelecao('010701'));
        $this->assertCount(11, $servicos->nbsParaSelecao('010101'));
        $this->assertArrayHasKey('1.1501.30.00', $servicos->nbsParaSelecao('010701'));
    }

    /**
     * O codigo antigo, gravado como subitem antes da tabela de cTribNac existir,
     * ainda responde: e o formato que o campo tinha.
     */
    public function test_codigo_antigo_gravado_como_subitem_ainda_acha_a_nbs(): void
    {
        $this->carregarTabelas();

        $this->assertCount(4, app(BuscaDeCodigosDeServico::class)->nbsParaSelecao('01.07'));
    }

    /**
     * Dos 200 subitens, 82 tem um item de NBS so. Nesses nao ha escolha a fazer
     * e o campo ja vem respondido; nos outros o maximo chega a setenta e cinco, e
     * apontar um seria decidir conteudo fiscal no lugar de quem emite.
     */
    public function test_a_nbs_unica_vem_escolhida_e_a_ambigua_nao(): void
    {
        $this->carregarTabelas();

        $servicos = app(BuscaDeCodigosDeServico::class);
        $unicos = ItemDaNbs::query()
            ->selectRaw('item_lista_servico, count(*) as total')
            ->groupBy('item_lista_servico')
            ->havingRaw('count(*) = 1')
            ->pluck('item_lista_servico');

        $this->assertCount(82, $unicos);
        $this->assertNull($servicos->nbsUnicaDoCodigo('010701'));
    }

    /**
     * O 990101 nao tem correlacao no anexo, e isso e da fonte: o Anexo VIII
     * traz 99.02.01, 99.03.01 e 99.04.01, e nada para 99.01. A tela avisa em vez
     * de oferecer lista vazia sem explicacao.
     */
    public function test_servico_sem_correlacao_no_anexo_nao_oferece_nbs(): void
    {
        $this->carregarTabelas();

        $this->assertSame([], app(BuscaDeCodigosDeServico::class)->nbsParaSelecao('990101'));
    }

    public function test_a_nbs_escolhida_chega_a_dps(): void
    {
        $this->carregarTabelas();

        $nota = Nota::factory()->create(['nbs' => '1.1501.30.00']);

        $this->assertSame('1.1501.30.00', $nota->servicoPrestado()->paraApi()['cNBS']);
    }

    /**
     * Sem declaracao de IBS/CBS o campo nao tem o que dizer, e o `semVazios()`
     * do ConstrutorDps poda o vazio.
     */
    public function test_nota_sem_nbs_nao_declara_o_campo(): void
    {
        $this->assertSame('', Nota::factory()->create(['nbs' => null])->servicoPrestado()->paraApi()['cNBS']);
    }

    /**
     * O campo mora no grupo de IBS/CBS porque e ali que a obrigacao nasce: a
     * E0322 exige a NBS quando a nota declara IBS/CBS, e nao quando ela descreve
     * o servico.
     */
    public function test_o_campo_aparece_com_a_declaracao_de_ibs_cbs(): void
    {
        $this->actingAs(User::factory()->create());
        $this->carregarTabelas();
        Empresa::factory()->create();

        Livewire::test(CreateNota::class)
            ->assertDontSee('Item da NBS')
            ->fillForm(['tem_ibs_cbs' => true])
            ->assertSee('Item da NBS')
            ->assertSee('São 4 itens correlacionados a este serviço pelo Anexo VIII.');
    }

    private function carregarTabelas(): void
    {
        app(ImportarListaDeServicos::class)->executar();
        app(ImportarCodigosDeTributacaoNacional::class)->executar();
        app(ImportarCorrelacaoNbs::class)->executar();
    }
}
