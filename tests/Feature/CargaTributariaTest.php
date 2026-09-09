<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Cargas\ImportarCargasTributarias;
use App\Actions\Cargas\TabelaIbptOficial;
use App\Consultas\BuscaDeCargasTributarias;
use App\Domain\ValueObjects\Dinheiro;
use App\Filament\Resources\CargasTributarias\Pages\ListCargasTributarias;
use App\Filament\Resources\Notas\Pages\CreateNota;
use App\Filament\Resources\Notas\Pages\EditNota;
use App\Models\CargaTributariaAproximada;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * A carga tributária aproximada da Lei da Transparência (12.741/2012): quanto
 * de tributo está embutido no preço.
 *
 * O ponto que estes testes protegem é a leitura do arquivo do IBPT **no formato
 * em que ele é distribuído**, sem conversão pelo caminho. Quem baixa a tabela
 * com o CNPJ da empresa precisa poder jogá-la aqui como veio, senão a origem
 * oficial vira um passo manual e o dado envelhece.
 */
class CargaTributariaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O cabeçalho e as três linhas que importam: uma NCM, uma NBS e uma da LC
     * 116. Só a última é serviço.
     */
    private function csvOficial(): string
    {
        return implode("\n", [
            'codigo;ex;tipo;descricao;nacionalfederal;importadosfederal;estadual;municipal;vigenciainicio;vigenciafim;chave;versao;fonte',
            '01012100;;0;"Cavalos reprodutores";13.45;15.45;19.00;0.00;20/08/2026;30/09/2026;A906AF;26.2.A;IBPT',
            '101011000;;1;"Servicos de construcao";13.45;15.45;0.00;4.93;20/08/2026;30/09/2026;A906AF;26.2.A;IBPT',
            '0107;;2;"Suporte técnico em informática, inclusive manutenção.";13.45;15.45;0.00;2.95;20/08/2026;30/09/2026;A906AF;26.2.A;IBPT',
        ]);
    }

    private function gravar(string $nome, string $conteudo): string
    {
        $caminho = storage_path("framework/testing/{$nome}");

        @mkdir(dirname($caminho), recursive: true);
        file_put_contents($caminho, mb_convert_encoding($conteudo, 'ISO-8859-1', 'UTF-8'));

        return $caminho;
    }

    public function test_o_arquivo_local_carrega_a_tabela_inteira(): void
    {
        $total = app(ImportarCargasTributarias::class)->executar();

        // 198 itens da LC 116 em 27 unidades federativas.
        $this->assertSame(5346, $total);

        $suporte = CargaTributariaAproximada::query()->where(['codigo' => '0107', 'uf' => 'RJ'])->sole();

        $this->assertSame('13.4500', $suporte->percentual_federal);
        $this->assertSame('2.9500', $suporte->percentual_municipal);
        $this->assertSame('26.2.A', $suporte->versao);
    }

    /**
     * Só o percentual municipal muda de estado para estado; federal e estadual
     * valem no país inteiro. É por isso que o arquivo local guarda a forma
     * compacta e a tabela guarda a linha por UF.
     */
    public function test_so_o_percentual_municipal_muda_por_uf(): void
    {
        app(ImportarCargasTributarias::class)->executar();

        $rio = CargaTributariaAproximada::query()->where(['codigo' => '0107', 'uf' => 'RJ'])->sole();
        $sampa = CargaTributariaAproximada::query()->where(['codigo' => '0107', 'uf' => 'SP'])->sole();

        $this->assertSame($rio->percentual_federal, $sampa->percentual_federal);
        $this->assertNotSame($rio->percentual_municipal, $sampa->percentual_municipal);
    }

    public function test_le_o_csv_como_o_ibpt_o_distribui(): void
    {
        $cargas = (new TabelaIbptOficial($this->gravar('TabelaIBPTaxRJ26.2.A.csv', $this->csvOficial())))->cargas();

        $this->assertCount(1, $cargas);
        $this->assertSame([
            'codigo' => '0107',
            'uf' => 'RJ',
            'descricao' => 'Suporte técnico em informática, inclusive manutenção.',
            'percentual_federal' => 13.45,
            'percentual_federal_importado' => 15.45,
            'percentual_estadual' => 0.0,
            'percentual_municipal' => 2.95,
            'vigencia_inicio' => '2026-08-20',
            'vigencia_fim' => '2026-09-30',
            'versao' => '26.2.A',
        ], $cargas[0]);
    }

    /**
     * NCM e NBS convivem no mesmo arquivo, e são a maior parte dele: mais de
     * doze mil linhas contra 198. Uma NFS-e não usa nenhuma das duas.
     */
    public function test_descarta_ncm_e_nbs_do_mesmo_arquivo(): void
    {
        $cargas = (new TabelaIbptOficial($this->gravar('TabelaIBPTaxRJ26.2.A.csv', $this->csvOficial())))->cargas();

        $this->assertSame(['0107'], array_column($cargas, 'codigo'));
    }

    /**
     * A distribuição é um ZIP com um CSV por UF, e a UF está no nome do
     * arquivo, não dentro dele.
     */
    public function test_le_o_zip_com_um_arquivo_por_uf(): void
    {
        $caminho = storage_path('framework/testing/TabelaIBPTax_26.2.A.zip');
        @mkdir(dirname($caminho), recursive: true);
        @unlink($caminho);

        $pacote = new ZipArchive;
        $pacote->open($caminho, ZipArchive::CREATE);

        foreach (['RJ' => '2.95', 'SP' => '2.70'] as $uf => $municipal) {
            $pacote->addFromString(
                "TabelaIBPTax{$uf}26.2.A.csv",
                mb_convert_encoding(str_replace(';2.95;', ";{$municipal};", $this->csvOficial()), 'ISO-8859-1', 'UTF-8'),
            );
        }

        $pacote->close();

        $cargas = (new TabelaIbptOficial($caminho))->cargas();

        $this->assertSame(['RJ', 'SP'], array_column($cargas, 'uf'));
        $this->assertSame([2.95, 2.7], array_column($cargas, 'percentual_municipal'));
    }

    /**
     * O arquivo vem em latin-1. Sem a conversão, "Suporte técnico" chega
     * quebrado ao banco e nunca mais se conserta.
     */
    public function test_o_acento_do_latin1_chega_inteiro(): void
    {
        $cargas = (new TabelaIbptOficial($this->gravar('TabelaIBPTaxRJ26.2.A.csv', $this->csvOficial())))->cargas();

        $this->assertSame('Suporte técnico em informática, inclusive manutenção.', $cargas[0]['descricao']);
    }

    public function test_arquivo_sem_uf_no_nome_falha_dizendo_o_que_se_espera(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TabelaIBPTaxRJ26.2.A.csv');

        (new TabelaIbptOficial($this->gravar('tabela.csv', $this->csvOficial())))->cargas();
    }

    public function test_planilha_sem_item_da_lc116_e_recusada(): void
    {
        $semServico = implode("\n", array_slice(explode("\n", $this->csvOficial()), 0, 3));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LC 116');

        (new TabelaIbptOficial($this->gravar('TabelaIBPTaxRJ26.2.A.csv', $semServico)))->cargas();
    }

    /**
     * A tabela do IBPT nunca desceu abaixo do subitem da LC 116, e escreve
     * "0107". A nota guarda o cTribNac, que e mais fino ("010701"), e o cadastro
     * antigo guardava o subitem pontuado ("01.07"): os tres precisam cair na
     * mesma linha.
     */
    public function test_a_consulta_reduz_o_codigo_ao_subitem_da_lista(): void
    {
        CargaTributariaAproximada::factory()->create(['codigo' => '0107', 'uf' => 'RJ']);

        $busca = app(BuscaDeCargasTributarias::class);

        foreach (['010701', '01.07', '0107'] as $escrito) {
            $carga = $busca->paraServico($escrito, 'RJ');

            $this->assertNotNull($carga, "O código {$escrito} tinha que achar o subitem 0107.");
            $this->assertSame(13.45, $carga->federal()->percentual);
        }
    }

    /**
     * O codigo do servico virou seletor, e chave numerica de array PHP nao e
     * texto: "140101" chega aqui como 140101. Recusar o que nao e string faria
     * a carga sumir sem aviso em todo item da lista a partir do 10.01, e o
     * campo dos totais aproximados ficaria vazio sem nada explicando por que.
     */
    public function test_o_codigo_que_chega_como_inteiro_acha_a_mesma_linha(): void
    {
        CargaTributariaAproximada::factory()->create(['codigo' => '1401', 'uf' => 'RJ']);

        $carga = app(BuscaDeCargasTributarias::class)->paraServico(140101, 'RJ');

        $this->assertNotNull($carga);
        $this->assertSame(13.45, $carga->federal()->percentual);
    }

    public function test_a_consulta_nao_confunde_uf(): void
    {
        CargaTributariaAproximada::factory()->create(['codigo' => '0107', 'uf' => 'RJ']);

        $this->assertNull(app(BuscaDeCargasTributarias::class)->paraServico('010701', 'SP'));
    }

    /**
     * O IBPT publica tabela nova a cada poucos meses. Emitir com percentual
     * vencido não invalida a nota, mas descumpre a lei que mandou destacá-lo,
     * então a tela precisa saber distinguir uma da outra.
     */
    /**
     * A tela de tributação diz de onde veio o percentual e até quando ele vale.
     * Emitir com tabela vencida não invalida a nota, mas descumpre a lei que
     * mandou destacar o valor, então o aviso precisa estar onde se preenche.
     */
    public function test_o_formulario_avisa_quando_a_tabela_do_ibpt_venceu(): void
    {
        $this->actingAs(User::factory()->create());
        CargaTributariaAproximada::factory()->vencida()->create(['codigo' => '0107', 'uf' => 'RJ']);

        // O emitente da fábrica é do Rio, e o serviço da nota é o item 01.07.
        $nota = Nota::factory()->create();

        // O grupo dos totais só aparece depois do "sim": é lá que a frase da
        // origem da carga acompanha os campos.
        Livewire::test(EditNota::class, ['record' => $nota->getKey()])
            ->fillForm(['tem_totais' => 1])
            ->assertSee('TABELA VENCIDA em');
    }

    public function test_o_formulario_diz_a_vigencia_quando_a_tabela_esta_valida(): void
    {
        $this->actingAs(User::factory()->create());
        CargaTributariaAproximada::factory()->create(['codigo' => '0107', 'uf' => 'RJ']);

        Livewire::test(EditNota::class, ['record' => Nota::factory()->create()->getKey()])
            ->fillForm(['tem_totais' => 1])
            ->assertSee('vigente até');
    }

    /**
     * A listagem diz de quando é a tabela carregada. Sem isso, uma tabela de
     * dois anos atrás parece tão atual quanto a de ontem.
     */
    public function test_a_listagem_diz_a_versao_e_a_vigencia_da_tabela(): void
    {
        $this->actingAs(User::factory()->create());
        CargaTributariaAproximada::factory()->create();

        Livewire::test(ListCargasTributarias::class)
            ->assertSee('TabelaIBPTax 26.2.A')
            ->assertSee('1 linhas');
    }

    public function test_a_listagem_sem_tabela_nenhuma_diz_isso(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ListCargasTributarias::class)->assertSee('Nenhuma tabela carregada.');
    }

    public function test_a_tabela_sabe_dizer_que_venceu(): void
    {
        $vigente = CargaTributariaAproximada::factory()->create(['codigo' => '0107']);
        $vencida = CargaTributariaAproximada::factory()->vencida()->create(['codigo' => '0108']);

        $this->assertFalse($vigente->estaVencida());
        $this->assertTrue($vencida->estaVencida());
    }

    /**
     * A tabela sozinha, sem o ISSQN da operação: é o caminho de quem não sabe o
     * número, e o que o percentual entrega é a média. 2.500 × 13,45% e × 2,95%,
     * os valores do item 01.07 no Rio na versão 26.2.A.
     *
     * O estadual sai zero, e não é engano: serviço não tem ICMS.
     */
    public function test_a_carga_vira_os_tres_totais_sobre_o_preco(): void
    {
        $carga = CargaTributariaAproximada::factory()->create();

        $totais = $carga->totaisSobre(Dinheiro::deReais(2500));

        $this->assertSame(336.25, $totais->federais->emReais());
        $this->assertSame(0.0, $totais->estaduais->emReais());
        $this->assertSame(73.75, $totais->municipais->emReais());
        $this->assertFalse($totais->estaZerado());
    }

    /**
     * O grupo `totTrib` só existe se houver o que declarar. Carga zerada não é
     * declaração: seria dizer que não há tributo embutido num serviço tributado.
     */
    public function test_carga_zerada_nao_declara_nada(): void
    {
        $carga = CargaTributariaAproximada::factory()->create([
            'percentual_federal' => 0,
            'percentual_estadual' => 0,
            'percentual_municipal' => 0,
        ]);

        $this->assertTrue($carga->totaisSobre(Dinheiro::deReais(2500))->estaZerado());
    }

    /**
     * O botão que traz a carga da tabela, pelo item da LC 116 e pela UF do
     * emitente. Foi escrito primeiro no `belowContent` do campo, onde não
     * renderizava: este teste existe para que ele não volte a sumir.
     *
     * R$ 1.000,00 no item 01.07: 13,45% federal da tabela, e o municipal é o
     * ISSQN da própria nota, R$ 50,00 a 5%. A média estadual da tabela seria
     * R$ 29,50, e é justamente o número que não deve aparecer aqui.
     *
     * O estado esperado é texto formatado, e não número, porque quem manda
     * depois de um `$set()` é a máscara do campo.
     */
    public function test_o_botao_traz_a_carga_do_ibpt_para_os_tres_campos(): void
    {
        $this->actingAs(User::factory()->create());
        app(ImportarCargasTributarias::class)->executar();

        $nota = Nota::factory()->create([
            'codigo_servico' => '010701',
            'valor_servico' => 1000,
            'aliquota_iss' => 5,
        ]);

        Livewire::test(EditNota::class, ['record' => $nota->getKey()])
            ->fillForm(['tem_totais' => true])
            ->callAction(TestAction::make('calcularPelaTabelaDoIbpt')->schemaComponent('carga-do-ibpt'))
            ->assertSchemaStateSet([
                'total_tributos_federais' => '134,50',
                'total_tributos_estaduais' => '0,00',
                'total_tributos_municipais' => '50,00',
            ]);
    }

    /**
     * Serviço que a tabela não cobre não pode preencher zero em silêncio: zero
     * declarado é declaração falsa, e quem clicou precisa saber que a tabela
     * não tinha resposta.
     */
    public function test_servico_fora_da_tabela_avisa_em_vez_de_preencher_zero(): void
    {
        $this->actingAs(User::factory()->create());

        $nota = Nota::factory()->create(['codigo_servico' => '99.99', 'valor_servico' => 1000]);

        Livewire::test(EditNota::class, ['record' => $nota->getKey()])
            ->fillForm(['tem_totais' => true])
            ->callAction(TestAction::make('calcularPelaTabelaDoIbpt')->schemaComponent('carga-do-ibpt'))
            ->assertNotified(__('Sem carga tributária para este serviço'))
            ->assertSchemaStateSet(['total_tributos_federais' => null]);
    }

    /**
     * O municipal é o ISSQN da operação, e não a média da tabela.
     *
     * O art. 5º do Decreto 8.264/2014 diz que o valor "será apurado sobre cada
     * operação", e põe a tabela semestral como alternativa "a critério das
     * empresas vendedoras". Numa NFS-e o ISS é sabido, então a média é a
     * resposta pior: 2,95% é o que os municípios do estado do Rio cobram em
     * média pelo item 01.07, e a capital cobra 5%.
     */
    public function test_o_issqn_da_operacao_substitui_a_media_municipal(): void
    {
        $carga = CargaTributariaAproximada::factory()->create();

        $comIssqn = $carga->totaisSobre(Dinheiro::deReais(1000), Dinheiro::deReais(50));
        $semIssqn = $carga->totaisSobre(Dinheiro::deReais(1000));

        $this->assertSame(50.0, $comIssqn->municipais->emReais());
        $this->assertSame(29.5, $semIssqn->municipais->emReais());

        // O federal e o estadual continuam saindo da tabela nos dois casos: é
        // só o municipal que a nota sabe melhor que o IBPT.
        $this->assertSame(134.5, $comIssqn->federais->emReais());
        $this->assertSame($semIssqn->federais->emReais(), $comIssqn->federais->emReais());
    }

    /**
     * Operação imune, isenta ou de não incidência tem ISSQN zero, e é zero que
     * entra no municipal. O § 1º do art. 3º do mesmo decreto manda não computar
     * o que foi eximido por imunidade, isenção, redução ou não incidência.
     *
     * Com o percentual da tabela, uma operação imune declararia 2,95% de carga
     * municipal que ninguém pagou.
     */
    public function test_operacao_sem_issqn_nao_declara_carga_municipal(): void
    {
        $carga = CargaTributariaAproximada::factory()->create();

        $totais = $carga->totaisSobre(Dinheiro::deReais(1000), Dinheiro::zero());

        $this->assertSame(0.0, $totais->municipais->emReais());
        $this->assertSame(134.5, $totais->federais->emReais());
    }

    /**
     * A nota nova já abre com os totais respondidos e calculados. A Lei da
     * Transparência diz que a informação "deverá constar", e deixar a pergunta
     * em "não" por padrão fazia toda nota sair sem ela por inércia.
     *
     * O preenchimento acontece quando o valor do serviço é informado, porque é
     * dele que os percentuais dependem: 2.500 × 13,45% federal, e o municipal
     * é o ISSQN da nota, 2.500 × 5%.
     */
    public function test_nota_nova_ja_vem_com_os_totais_respondidos_e_calculados(): void
    {
        $this->actingAs(User::factory()->create());
        app(ImportarCargasTributarias::class)->executar();

        Empresa::factory()->create(['codigo_servico_padrao' => '010701', 'aliquota_iss_padrao' => 5]);

        Livewire::test(CreateNota::class)
            ->assertSchemaStateSet(['tem_totais' => true])
            ->fillForm(['valor_servico' => 2500])
            ->assertSchemaStateSet([
                'total_tributos_federais' => '336,25',
                'total_tributos_estaduais' => '0,00',
                'total_tributos_municipais' => '125,00',
            ]);
    }

    /**
     * Sem a tabela carregada não há de onde tirar o número, e aí a pergunta
     * volta a "não". Oferecer "sim" sem conseguir responder só produziria uma
     * nota que não grava até alguém digitar três valores que ninguém sabe.
     */
    public function test_sem_a_tabela_a_pergunta_dos_totais_vem_nao(): void
    {
        $this->actingAs(User::factory()->create());

        Empresa::factory()->create(['codigo_servico_padrao' => '010701']);

        Livewire::test(CreateNota::class)->assertSchemaStateSet(['tem_totais' => false]);
    }

    /**
     * O que já foi digitado não é substituído: quem escreveu um total à mão não
     * pode vê-lo trocado ao corrigir o valor do serviço.
     */
    public function test_total_digitado_a_mao_sobrevive_a_mudanca_do_valor(): void
    {
        $this->actingAs(User::factory()->create());
        app(ImportarCargasTributarias::class)->executar();

        Empresa::factory()->create(['codigo_servico_padrao' => '010701', 'aliquota_iss_padrao' => 5]);

        Livewire::test(CreateNota::class)
            ->fillForm(['total_tributos_federais' => '999,00'])
            ->fillForm(['valor_servico' => 2500])
            ->assertSchemaStateSet(['total_tributos_federais' => '999,00']);
    }
}
