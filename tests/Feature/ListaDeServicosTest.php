<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Servicos\ArquivoDeCodigosDeTributacao;
use App\Actions\Servicos\ImportarCodigosDeTributacaoNacional;
use App\Actions\Servicos\ImportarListaDeServicos;
use App\Actions\Servicos\PortalDaNfseNacional;
use App\Consultas\BuscaDeCodigosDeServico;
use App\Domain\Enums\StatusNota;
use App\Filament\Resources\CodigosDeTributacaoNacional\Pages\ListCodigosDeTributacaoNacional;
use App\Filament\Resources\Empresas\Pages\EditEmpresa;
use App\Filament\Resources\Notas\Pages\CreateNota;
use App\Models\Cliente;
use App\Models\CodigoDeTributacaoNacional;
use App\Models\Empresa;
use App\Models\ItemDaListaDeServicos;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as ClienteHttp;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * As duas listas do bloco "Serviço": a anexa a LC 116/2003, que os provedores
 * ABRASF leem em `ItemListaServico`, e o `cTribNac` do Padrao Nacional, que a
 * DPS declara em `serv.cServ`. Uma esta dentro da outra: o codigo de tributacao
 * e o subitem da lista mais o desdobramento.
 */
class ListaDeServicosTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_arquivo_local_carrega_a_lista_anexa_a_lc_116(): void
    {
        $total = app(ImportarListaDeServicos::class)->executar();

        $this->assertSame(200, $total);

        $suporte = ItemDaListaDeServicos::query()->where('codigo', '0107')->sole();

        $this->assertSame('01.07', $suporte->pontuado());
        $this->assertStringStartsWith('Suporte técnico em informática', $suporte->descricao);
    }

    /**
     * O texto que vale e o alterado. O Planalto publica a lei consolidada, com
     * a redacao original ao lado da nova: se a leitura ficasse com a primeira,
     * o subitem 01.03 ainda diria so "Processamento de dados e congeneres".
     */
    public function test_a_lista_traz_a_redacao_em_vigor_e_nao_a_original(): void
    {
        app(ImportarListaDeServicos::class)->executar();

        $processamento = ItemDaListaDeServicos::query()->where('codigo', '0103')->sole();

        $this->assertStringContainsString('armazenamento ou hospedagem', $processamento->descricao);
    }

    public function test_o_arquivo_local_carrega_os_codigos_de_tributacao_nacional(): void
    {
        $total = app(ImportarCodigosDeTributacaoNacional::class)->executar();

        $this->assertSame(335, $total);

        $suporte = CodigoDeTributacaoNacional::query()->where('codigo', '010701')->sole();

        $this->assertSame('0107', $suporte->item_lista_servico);
        $this->assertSame('01.07', $suporte->itemPontuado());
    }

    /**
     * Os dois arquivos vao juntos no projeto e vem de origens diferentes: a lei
     * no Planalto e a tabela no Portal Nacional. Este teste e o que cobra que
     * continuem falando do mesmo servico depois de qualquer atualizacao.
     *
     * A excecao e o 9901, "serviços sem a incidência de ISSQN e ICMS": ele so
     * existe na tabela nacional, e nao ha subitem da LC 116 correspondente.
     */
    public function test_todo_codigo_de_tributacao_cai_num_subitem_da_lista(): void
    {
        app(ImportarListaDeServicos::class)->executar();
        app(ImportarCodigosDeTributacaoNacional::class)->executar();

        $orfaos = CodigoDeTributacaoNacional::query()
            ->whereNotIn('item_lista_servico', ItemDaListaDeServicos::query()->select('codigo'))
            ->pluck('codigo')
            ->all();

        $this->assertSame(['990101'], $orfaos);
    }

    public function test_a_leitura_do_portal_nacional_pega_um_codigo_por_linha(): void
    {
        Http::fake(['exemplo.test/*' => Http::response($this->paginaDoPortal())]);

        $codigos = $this->portal()->codigos();

        $this->assertSame([
            ['codigo' => '010101', 'item_lista_servico' => '0101', 'descricao' => 'Análise e desenvolvimento de sistemas.'],
            ['codigo' => '010301', 'item_lista_servico' => '0103', 'descricao' => 'Processamento de dados e congêneres.'],
            ['codigo' => '010302', 'item_lista_servico' => '0103', 'descricao' => 'Armazenamento ou hospedagem de dados.'],
        ], $codigos);
    }

    /**
     * O WAF do portal responde 403 ao `User-Agent` padrao do cliente HTTP. O
     * defeito e mudo: a atualizacao pela tela para de funcionar e a mensagem so
     * diz "403", sem indicar que o problema e o cabecalho.
     */
    public function test_a_leitura_se_anuncia_como_navegador(): void
    {
        Http::fake(['exemplo.test/*' => Http::response($this->paginaDoPortal())]);

        $this->portal()->codigos();

        Http::assertSent(fn (Request $pedido): bool => str_starts_with($pedido->header('User-Agent')[0] ?? '', 'Mozilla/'));
    }

    /**
     * A pagina poe um codigo por paragrafo, mas nada garante que o texto dele
     * venha limpo. Quebrar linha em toda tag partia a descricao no primeiro
     * negrito ou link, e o pedaco sobrevivente entrava na tabela por cima da
     * descricao inteira que ja estava la: um `<b>` acrescentado no portal
     * corromperia a linha sem nenhum erro aparecer.
     */
    public function test_marcacao_dentro_do_paragrafo_nao_corta_a_descricao(): void
    {
        Http::fake(['exemplo.test/*' => Http::response(
            '<html><body><script>var x = "019999 - lixo";</script>'
            .'<p>010101 - Análise e <b>desenvolvimento</b> de <i>sistemas</i>.</p></body></html>'
        )]);

        $codigos = $this->portal()->codigos();

        $this->assertSame([
            ['codigo' => '010101', 'item_lista_servico' => '0101', 'descricao' => 'Análise e desenvolvimento de sistemas.'],
        ], $codigos);
    }

    /**
     * `<br>` e a marcacao mais comum DENTRO da descricao, e nao fim de bloco.
     * Trata-la como fim cortava o texto no meio, e o pedaco sobrevivente ainda
     * casa "NNNNNN - descricao": entrava na tabela por cima do texto inteiro sem
     * mudar a contagem, entao nem a guarda de leitura parcial percebia.
     */
    public function test_quebra_de_linha_dentro_do_paragrafo_nao_corta_a_descricao(): void
    {
        Http::fake(['exemplo.test/*' => Http::response(
            '<html><body><p>010101 - Análise e desenvolvimento<br>de sistemas.</p></body></html>'
        )]);

        $this->assertSame(
            'Análise e desenvolvimento de sistemas.',
            $this->portal()->codigos()[0]['descricao'],
        );
    }

    /**
     * A leitura toda depende do modificador `u`, e `preg_replace` com `u`
     * devolve `null` diante de byte que nao seja UTF-8. Com o `(string)` na
     * frente isso vira string vazia e a linha some sem erro: numa pagina
     * Latin-1, que ainda e comum em .gov.br, sumiriam justamente as linhas com
     * acento, que sao quase todas.
     */
    public function test_pagina_em_latin1_nao_perde_as_linhas_com_acento(): void
    {
        $pagina = mb_convert_encoding(
            '<html><body><p>010301 - Processamento de informação.</p><p>010201 - Programacao.</p></body></html>',
            'ISO-8859-1',
            'UTF-8',
        );

        Http::fake(['exemplo.test/*' => Http::response($pagina)]);

        $descricoes = array_column($this->portal()->codigos(), 'descricao', 'codigo');

        $this->assertSame('Processamento de informação.', $descricoes['010301'] ?? null);
        $this->assertSame('Programacao.', $descricoes['010201'] ?? null);
    }

    /**
     * A gravacao e `upsert`: leitura pela metade nao esvazia a tabela, mistura o
     * que veio com o que ficou e ainda devolve numero verde na tela. Recusar
     * antes de gravar e o unico ponto em que isso aparece.
     */
    public function test_leitura_muito_menor_que_a_tabela_e_recusada(): void
    {
        app(ImportarCodigosDeTributacaoNacional::class)->executar();
        Http::fake(['exemplo.test/*' => Http::response($this->paginaDoPortal())]);

        try {
            (new ImportarCodigosDeTributacaoNacional($this->portal(), app(ArquivoDeCodigosDeTributacao::class)))->executar();
            $this->fail('A importação tinha que recusar uma leitura de 3 códigos contra 335 carregados.');
        } catch (RuntimeException $falha) {
            $this->assertStringContainsString('leitura incompleta', $falha->getMessage());
        }

        $this->assertSame(335, CodigoDeTributacaoNacional::query()->count());
    }

    public function test_pagina_sem_os_codigos_conhecidos_falha_dizendo_o_que_mudou(): void
    {
        Http::fake(['exemplo.test/*' => Http::response('<html><body>Portal em manutenção</body></html>')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('010101 - descrição');

        $this->portal()->codigos();
    }

    public function test_portal_fora_do_ar_falha_dizendo_o_status(): void
    {
        Http::fake(['exemplo.test/*' => Http::response('', 503)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('503');

        $this->portal()->codigos();
    }

    /**
     * A busca precisa alcancar a descricao inteira, e nao so o rotulo: o rotulo
     * vem cortado em noventa caracteres, e e justamente a cauda que diz o que o
     * codigo exclui.
     */
    public function test_a_busca_acha_o_codigo_pelo_texto_do_servico(): void
    {
        app(ImportarCodigosDeTributacaoNacional::class)->executar();

        $achados = app(BuscaDeCodigosDeServico::class)->procurarCodigos('bancos de dados');

        $this->assertArrayHasKey('010701', $achados);
    }

    /**
     * Sao tres grafias para o mesmo subitem, e as tres sao lidas em algum lugar:
     * a LC 116 escreve "1.07", a tela mostra "01.07" e a coluna guarda "0107".
     * Quem digita a forma que leu na lei nao pode receber lista vazia.
     */
    #[DataProvider('grafiasDoSubitem')]
    public function test_a_busca_acha_o_subitem_em_qualquer_grafia(string $digitado): void
    {
        app(ImportarListaDeServicos::class)->executar();

        $achados = app(BuscaDeCodigosDeServico::class)->procurarItens($digitado);

        $this->assertArrayHasKey('01.07', $achados, "Digitar \"{$digitado}\" tinha que achar o subitem 01.07.");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function grafiasDoSubitem(): array
    {
        return [
            'como a tela mostra' => ['01.07'],
            'como a lei escreve' => ['1.07'],
            'como a coluna guarda' => ['0107'],
        ];
    }

    /**
     * O `LIKE` do SQLite so dobra caixa em ASCII: "A" acha "a", mas "Á" nao acha
     * "á". Quem digitava em maiusculas, ou tinha o teclado do celular
     * capitalizando sozinho, recebia lista vazia para palavra que esta em dezenas
     * de descricoes.
     */
    #[DataProvider('buscasEmMaiusculas')]
    public function test_a_busca_acha_palavra_acentuada_digitada_em_maiusculas(string $digitado, string $codigoEsperado): void
    {
        app(ImportarListaDeServicos::class)->executar();
        app(ImportarCodigosDeTributacaoNacional::class)->executar();

        $achados = app(BuscaDeCodigosDeServico::class)->procurarCodigos($digitado);

        $this->assertArrayHasKey($codigoEsperado, $achados, "Digitar \"{$digitado}\" não podia devolver lista vazia.");
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function buscasEmMaiusculas(): array
    {
        return [
            'tudo em maiúsculas' => ['ANÁLISE', '010101'],
            'como está na descrição' => ['Análise', '010101'],
            'tudo em minúsculas' => ['análise', '010101'],
            'com cedilha' => ['SERVIÇOS DE PESQUISAS', '020101'],
        ];
    }

    /**
     * `%` e `_` sao curingas do `LIKE`, e ninguem os digita querendo isso. Sem
     * limpa-los, um `%` sozinho devolvia a lista inteira apresentada como
     * resultado de busca.
     */
    public function test_curinga_de_sql_nao_vira_resultado_de_busca(): void
    {
        app(ImportarListaDeServicos::class)->executar();
        app(ImportarCodigosDeTributacaoNacional::class)->executar();

        $servicos = app(BuscaDeCodigosDeServico::class);

        $this->assertSame([], $servicos->procurarCodigos('%'));
        $this->assertSame([], $servicos->procurarItens('_'));
        $this->assertSame([], $servicos->procurarCodigos('   '));
    }

    /**
     * A tabela mostra o subitem pontuado e guarda os quatro digitos. Procurar
     * exatamente o que o cracha exibe nao podia devolver nada.
     */
    public function test_a_tabela_acha_o_subitem_como_ele_aparece_na_tela(): void
    {
        $this->actingAs(User::factory()->create());
        app(ImportarListaDeServicos::class)->executar();
        app(ImportarCodigosDeTributacaoNacional::class)->executar();

        Livewire::test(ListCodigosDeTributacaoNacional::class)
            ->loadTable()
            ->searchTable('01.07')
            ->assertCanSeeTableRecords(CodigoDeTributacaoNacional::query()->where('codigo', '010701')->get())
            ->assertCanNotSeeTableRecords(CodigoDeTributacaoNacional::query()->where('codigo', '010101')->get());
    }

    /**
     * O cTribNac carrega o subitem nos quatro primeiros digitos. Os dois campos
     * eram texto livre e aceitavam "01.07" nos dois, o que fazia um deles estar
     * sempre errado sem nada na tela dizendo isso.
     *
     * Os dois codigos aqui nao sao intercambiaveis: "140101" e chave numerica e
     * volta do formulario como inteiro; "010701" tem zero a esquerda e volta
     * como texto. A leitura precisa aguentar as duas formas, e o defeito que
     * este teste tranca e o seletor mudo, que aceitava a escolha e nao
     * preenchia nada.
     */
    #[DataProvider('codigosDeServico')]
    public function test_escolher_o_codigo_do_servico_preenche_o_item_da_lista(string $codigo, string $item): void
    {
        $this->actingAs(User::factory()->create());
        app(ImportarListaDeServicos::class)->executar();
        app(ImportarCodigosDeTributacaoNacional::class)->executar();
        Empresa::factory()->create();

        Livewire::test(CreateNota::class)
            ->fillForm(['codigo_servico' => $codigo])
            ->assertFormSet(['item_lista_servico' => $item]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function codigosDeServico(): array
    {
        return [
            'chave numérica' => ['140101', '14.01'],
            'chave com zero à esquerda' => ['010701', '01.07'],
        ];
    }

    /**
     * O preenchimento automatico e conveniencia, e nao autoridade. Item
     * escolhido a mao fica: provedor ABRASF as vezes pede outro, e isso e
     * decisao de quem emite. E a mesma regra que o botao dos totais
     * aproximados ja seguia.
     */
    public function test_item_escolhido_a_mao_sobrevive_a_troca_do_codigo(): void
    {
        $this->actingAs(User::factory()->create());
        app(ImportarListaDeServicos::class)->executar();
        app(ImportarCodigosDeTributacaoNacional::class)->executar();
        Empresa::factory()->create();

        Livewire::test(CreateNota::class)
            ->fillForm(['codigo_servico' => '010701'])
            ->assertFormSet(['item_lista_servico' => '01.07'])
            ->fillForm(['item_lista_servico' => '17.01'])
            ->fillForm(['codigo_servico' => '140101'])
            ->assertFormSet(['item_lista_servico' => '17.01']);
    }

    /**
     * O 990101 e "serviços sem a incidência de ISSQN e ICMS": carrega 9901, que
     * nao existe na lista da LC 116. Preencher pelo formato punha "99.01" num
     * campo que so aceita subitem, e o valor chegaria assim a provedor ABRASF.
     * Sem item para preencher, o campo fica vazio, que e o que a lei diz.
     */
    public function test_codigo_sem_subitem_na_lei_nao_inventa_item_da_lista(): void
    {
        $this->actingAs(User::factory()->create());
        app(ImportarListaDeServicos::class)->executar();
        app(ImportarCodigosDeTributacaoNacional::class)->executar();
        Empresa::factory()->create();

        $this->assertNull(app(BuscaDeCodigosDeServico::class)->itemDoCodigo('990101'));

        Livewire::test(CreateNota::class)
            ->fillForm(['codigo_servico' => '010701'])
            ->assertFormSet(['item_lista_servico' => '01.07'])
            ->fillForm(['codigo_servico' => '990101'])
            ->assertFormSet(['item_lista_servico' => null]);
    }

    /**
     * O cadastro do emitente repete o fecho da nota, e codigo duplicado e onde
     * as duas pontas se soltam. As duas regras valem nos dois lugares: item
     * escolhido a mao fica, e codigo sem subitem na lei nao inventa item.
     */
    public function test_o_cadastro_do_emitente_segue_as_mesmas_duas_regras(): void
    {
        $this->actingAs(User::factory()->create());
        app(ImportarListaDeServicos::class)->executar();
        app(ImportarCodigosDeTributacaoNacional::class)->executar();
        $empresa = Empresa::factory()->create();

        Livewire::test(EditEmpresa::class, ['record' => $empresa->getKey()])
            ->fillForm(['codigo_servico_padrao' => '140101'])
            ->assertFormSet(['item_lista_servico_padrao' => '14.01'])
            ->fillForm(['item_lista_servico_padrao' => '17.01'])
            ->fillForm(['codigo_servico_padrao' => '010701'])
            ->assertFormSet(['item_lista_servico_padrao' => '17.01'])
            ->fillForm(['item_lista_servico_padrao' => null])
            ->fillForm(['codigo_servico_padrao' => '990101'])
            ->assertFormSet(['item_lista_servico_padrao' => null]);
    }

    public function test_a_nota_recusa_codigo_de_servico_fora_da_tabela(): void
    {
        $this->actingAs(User::factory()->create());
        app(ImportarCodigosDeTributacaoNacional::class)->executar();
        Empresa::factory()->create();

        Livewire::test(CreateNota::class)
            ->fillForm(['codigo_servico' => '999999'])
            ->call('create')
            ->assertHasFormErrors(['codigo_servico']);
    }

    /**
     * A coluna da descricao mostra o subitem da LC 116 embaixo do desdobramento,
     * e sem o eager load isso e uma consulta por linha desenhada.
     *
     * A conta e por consulta emitida, e nao por `relationLoaded`: depois do
     * desenho a relacao esta carregada nos dois casos, porque o proprio desenho
     * a carrega, uma linha de cada vez. So o SQL separa um do outro.
     *
     * O teste tambem tranca o nome do parametro do `modifyQueryUsing`: o
     * Filament injeta a consulta por nome, e com o parametro chamado de outra
     * coisa ele monta um builder sem model e a tela quebra ao carregar. O
     * `deferLoading` esconde isso de quem so abre a pagina.
     */
    public function test_a_tabela_traz_o_subitem_junto_e_nao_linha_a_linha(): void
    {
        $this->actingAs(User::factory()->create());
        app(ImportarListaDeServicos::class)->executar();
        app(ImportarCodigosDeTributacaoNacional::class)->executar();

        $consultasPorLinha = 0;

        DB::listen(function (QueryExecuted $consulta) use (&$consultasPorLinha): void {
            if (str_contains($consulta->sql, 'itens_da_lista_de_servicos') && str_contains($consulta->sql, 'limit 1')) {
                $consultasPorLinha++;
            }
        });

        Livewire::test(ListCodigosDeTributacaoNacional::class)->loadTable();

        $this->assertSame(0, $consultasPorLinha, 'O subitem tem que vir no eager load, e não uma consulta por linha.');
    }

    /**
     * Dos 200 subitens, 138 tem um unico desdobramento, e para esses "01.07" so
     * pode ser "010701". A conversao para ai: escolher entre dois desdobramentos
     * seria decidir conteudo fiscal no lugar de quem emite.
     */
    public function test_a_migration_converte_o_subitem_com_desdobramento_unico(): void
    {
        $nota = Nota::factory()->create();
        DB::table('notas')->where('id', $nota->getKey())->update([
            'codigo_servico' => '01.07',
            'item_lista_servico' => null,
        ]);

        $this->migrationDaConversao()->up();

        $convertida = DB::table('notas')->where('id', $nota->getKey())->sole();
        $this->assertSame('010701', $convertida->codigo_servico);
        $this->assertSame('01.07', $convertida->item_lista_servico);
    }

    /**
     * O subitem 04.02 tem cinco desdobramentos. Nao ha o que converter sem
     * inventar, e o registro precisa sair intacto.
     */
    public function test_a_migration_nao_escolhe_entre_desdobramentos(): void
    {
        $nota = Nota::factory()->create();
        DB::table('notas')->where('id', $nota->getKey())->update(['codigo_servico' => '04.02']);

        $this->migrationDaConversao()->up();

        $this->assertSame('04.02', DB::table('notas')->where('id', $nota->getKey())->value('codigo_servico'));
    }

    /**
     * As duas metades do subitem sao contadas separadamente, e nao os digitos em
     * fila. Sem isso "10.7" virava "0107", o suporte em informatica, quando quem
     * escreveu queria o item 10: a migration trocaria o servico da nota por
     * outro, calada e sem volta. O que nao fecha fica intacto.
     */
    #[DataProvider('grafiasNaMigration')]
    public function test_a_migration_so_converte_o_que_nao_e_palpite(string $gravado, string $esperado): void
    {
        $nota = Nota::factory()->create();
        DB::table('notas')->where('id', $nota->getKey())->update(['codigo_servico' => $gravado]);

        $this->migrationDaConversao()->up();

        $this->assertSame($esperado, DB::table('notas')->where('id', $nota->getKey())->value('codigo_servico'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function grafiasNaMigration(): array
    {
        return [
            'subitem pontuado' => ['01.07', '010701'],
            'subitem sem o zero' => ['1.07', '010701'],
            'item de dois dígitos' => ['10.07', '100701'],
            'subitem de um dígito é ambíguo' => ['10.7', '10.7'],
            'sem ponto e com três dígitos é ambíguo' => ['107', '107'],
            'texto livre' => ['consultoria', 'consultoria'],
        ];
    }

    /**
     * Rodar duas vezes nao pode mexer no que ja e cTribNac: seis digitos nao sao
     * subitem, e a segunda passagem tem que sair sem fazer nada.
     */
    public function test_a_migration_nao_mexe_no_que_ja_esta_convertido(): void
    {
        $nota = Nota::factory()->create(['codigo_servico' => '140101']);

        $this->migrationDaConversao()->up();

        $this->assertSame('140101', DB::table('notas')->where('id', $nota->getKey())->value('codigo_servico'));
    }

    /**
     * O que a migration nao consegue converter continua abrindo o formulario, e
     * gravando. Sem isto o `in:` que o Filament monta recusa o registro inteiro,
     * e nao da para corrigir a razao social por causa do codigo do servico.
     */
    public function test_codigo_antigo_aparece_no_seletor_e_nao_trava_o_registro(): void
    {
        $this->actingAs(User::factory()->create());
        app(ImportarListaDeServicos::class)->executar();
        app(ImportarCodigosDeTributacaoNacional::class)->executar();

        $empresa = Empresa::factory()->create();
        DB::table('empresas')->where('id', $empresa->getKey())->update(['codigo_servico_padrao' => '04.02']);

        Livewire::test(EditEmpresa::class, ['record' => $empresa->getKey()])
            ->assertSee('código antigo')
            ->fillForm(['razao_social' => 'Outro Nome LTDA'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Outro Nome LTDA', $empresa->refresh()->razao_social);
    }

    /**
     * A saida para codigo antigo e para o formato antigo do campo, e nao para
     * qualquer valor curto. Enquanto bastava "parecer subitem", dava para gravar
     * nota nova com `cServ` "9999": um codigo que nao existe em tabela nenhuma,
     * gravado sem um erro sequer e enviado assim para a prefeitura. E o mesmo
     * buraco que o seletor foi posto ali para fechar.
     */
    #[DataProvider('valoresForaDaTabela')]
    public function test_a_saida_para_codigo_antigo_so_vale_para_subitem_de_verdade(string $valor, bool $ehSubitem): void
    {
        app(ImportarListaDeServicos::class)->executar();
        app(ImportarCodigosDeTributacaoNacional::class)->executar();

        $servicos = app(BuscaDeCodigosDeServico::class);

        // O valor chega como o que o registro ja guarda, que e o unico caso em
        // que a saida existe.
        $rotulo = $servicos->rotuloDeCodigo($valor, $valor);

        $ehSubitem
            ? $this->assertStringContainsString('código antigo', (string) $rotulo)
            : $this->assertNull($rotulo, "\"{$valor}\" não é subitem da LC 116 e não podia virar escolha válida.");

        // E nunca para o que acabou de ser escolhido na tela.
        $this->assertNull($servicos->rotuloDeCodigo($valor, null));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function valoresForaDaTabela(): array
    {
        return [
            'subitem que existe' => ['04.02', true],
            'subitem sem o zero' => ['4.02', true],
            'quatro dígitos inventados' => ['9999', false],
            'zeros' => ['0000', false],
            'dígito solto' => ['1', false],
            'seis dígitos inventados' => ['999999', false],
            'texto' => ['abc', false],
        ];
    }

    /**
     * A saida existe para destravar registro antigo, e nao para abrir o campo.
     * O Filament abandona a regra `in:` assim que `getOptionLabelUsing` devolve
     * rotulo, entao sem prender a saida ao que o registro ja guarda dava para
     * gravar nota nova com o subitem "04.02" no lugar do cTribNac, e a DPS saia
     * com quatro digitos onde a prefeitura espera seis.
     */
    public function test_nota_nova_nao_grava_subitem_no_lugar_do_codigo(): void
    {
        $this->actingAs(User::factory()->create());
        app(ImportarListaDeServicos::class)->executar();
        app(ImportarCodigosDeTributacaoNacional::class)->executar();
        $empresa = Empresa::factory()->create();
        $cliente = Cliente::factory()->create();

        Livewire::test(CreateNota::class)
            ->fillForm([
                'empresa_id' => $empresa->getKey(),
                'cliente_id' => $cliente->getKey(),
                'cidade_prestacao_id' => $empresa->cidade_id,
                'competencia' => now()->startOfMonth()->toDateString(),
                'descricao_servico' => 'Análises clínicas',
                'codigo_servico' => '04.02',
                'valor_servico' => 1000,
                'aliquota_iss' => 5,
                'deducoes' => 0,
                'desconto_incondicionado' => 0,
                'desconto_condicionado' => 0,
                'tributacao_issqn' => 1,
                'retencao_issqn' => 1,
            ])
            ->call('create')
            ->assertHasFormErrors(['codigo_servico']);

        $this->assertSame(0, Nota::query()->count());
    }

    /**
     * O item tem saida propria, e mais larga que a do codigo de proposito: ele e
     * opcional e so provedor ABRASF o le, entao recusar um valor gravado fora do
     * formato trava a edicao do registro inteiro sem nada em troca.
     */
    public function test_item_gravado_fora_do_formato_nao_trava_o_emitente(): void
    {
        $this->actingAs(User::factory()->create());
        app(ImportarListaDeServicos::class)->executar();
        app(ImportarCodigosDeTributacaoNacional::class)->executar();

        $empresa = Empresa::factory()->create();
        DB::table('empresas')->where('id', $empresa->getKey())->update(['item_lista_servico_padrao' => '010701']);

        Livewire::test(EditEmpresa::class, ['record' => $empresa->getKey()])
            ->assertSee('fora da lista da LC 116')
            ->fillForm(['razao_social' => 'Outro Nome LTDA'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Outro Nome LTDA', $empresa->refresh()->razao_social);
    }

    /**
     * A migration reescreve coluna, e nota autorizada tem XML assinado na mao da
     * prefeitura dizendo o codigo antigo. Trocar a coluna poria o registro em
     * desacordo com o documento que existe la fora, e a substituta sairia
     * copiando o codigo trocado. Nao ha volta: o `down()` e vazio.
     */
    public function test_a_migration_nao_reescreve_nota_ja_transmitida(): void
    {
        $rascunho = Nota::factory()->create(['status' => StatusNota::Rascunho]);
        $autorizada = Nota::factory()->create(['status' => StatusNota::Autorizada]);
        DB::table('notas')->update(['codigo_servico' => '01.07']);

        $this->migrationDaConversao()->up();

        $this->assertSame('010701', DB::table('notas')->where('id', $rascunho->getKey())->value('codigo_servico'));
        $this->assertSame('01.07', DB::table('notas')->where('id', $autorizada->getKey())->value('codigo_servico'));
    }

    private function migrationDaConversao(): Migration
    {
        $migration = require database_path('migrations/2026_09_08_132231_converter_codigo_do_servico_para_ctribnac.php');
        assert($migration instanceof Migration);

        return $migration;
    }

    private function portal(): PortalDaNfseNacional
    {
        return new PortalDaNfseNacional(app(ClienteHttp::class), 'https://exemplo.test/codigos');
    }

    /**
     * A pagina do Portal Nacional poe um codigo por paragrafo, no formato
     * `NNNNNN - descricao`.
     */
    private function paginaDoPortal(): string
    {
        return <<<'HTML'
            <html><body>
            <h2>Código e Descrição dos Serviços</h2>
            <p>010101 - Análise e desenvolvimento de sistemas.</p>
            <p>010302 - Armazenamento ou hospedagem de dados.</p>
            <p>010301 - Processamento de dados e congêneres.</p>
            <p>Texto que não é código.</p>
            </body></html>
            HTML;
    }
}
