<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Consultas\BuscaDeCidades;
use App\Consultas\ClientesTomadores;
use App\Consultas\EmpresasEmitentes;
use App\Consultas\EstadoDaConfiguracao;
use App\Consultas\NumeracaoDaEmpresa;
use App\Consultas\PadroesDaEmpresa;
use App\Consultas\ResumoDeNotas;
use App\Domain\Enums\StatusNota;
use App\Domain\ValueObjects\CodigoIbge;
use App\Models\Cidade;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Nota;
use Database\Factories\CidadeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A camada `Consultas/` é de onde as telas tiram dados, nenhuma delas escreve
 * consulta à mão. Isso só compensa se o que está aqui for testado por si, e não
 * de raspão pela renderização de um widget: um contador que soma o status
 * errado passa despercebido numa asserção de "a página abriu".
 */
class ConsultasDoPainelTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_faturado_no_mes_soma_so_autorizada_e_so_a_competencia_corrente(): void
    {
        Nota::factory()->create(['status' => StatusNota::Autorizada, 'valor_servico' => 1000]);
        Nota::factory()->create(['status' => StatusNota::Autorizada, 'valor_servico' => 500]);

        // Mesmo valor, mas fora do mês e fora do status: nenhuma das duas entra.
        Nota::factory()->create([
            'status' => StatusNota::Autorizada,
            'valor_servico' => 9000,
            'competencia' => now()->subMonth()->startOfMonth(),
        ]);
        Nota::factory()->create(['status' => StatusNota::Rejeitada, 'valor_servico' => 7000]);

        $this->assertSame(1500.0, app(ResumoDeNotas::class)->valorAutorizadoNoMes()->emReais());
    }

    /**
     * O gráfico do painel lê esta lista da esquerda para a direita: o mês mais
     * antigo primeiro, o corrente por último. Invertida, ela conta a história
     * ao contrário, faturamento subindo onde estava caindo.
     */
    public function test_o_faturamento_dos_ultimos_meses_vem_do_mais_antigo_para_o_mais_recente(): void
    {
        Nota::factory()->create([
            'status' => StatusNota::Autorizada,
            'valor_servico' => 300,
            'competencia' => now()->startOfMonth()->subMonths(2),
        ]);
        Nota::factory()->create(['status' => StatusNota::Autorizada, 'valor_servico' => 100]);
        Nota::factory()->create(['status' => StatusNota::Autorizada, 'valor_servico' => 50]);

        $this->assertSame(
            [0.0, 0.0, 0.0, 300.0, 0.0, 150.0],
            app(ResumoDeNotas::class)->faturamentoDosUltimosMeses(),
        );
    }

    /**
     * A janela tem exatamente `meses` posições, contadas para trás incluindo o
     * mês corrente. O que caiu fora dela não aparece nem como resto no
     * primeiro balde.
     */
    public function test_a_janela_do_grafico_e_fechada_e_o_que_ficou_fora_nao_entra(): void
    {
        Nota::factory()->create([
            'status' => StatusNota::Autorizada,
            'valor_servico' => 800,
            'competencia' => now()->startOfMonth()->subMonths(3),
        ]);

        $this->assertSame([0.0, 0.0, 0.0], app(ResumoDeNotas::class)->faturamentoDosUltimosMeses(3));
    }

    public function test_em_aberto_e_pedem_acao_contam_situacoes_diferentes(): void
    {
        Nota::factory()->create(['status' => StatusNota::Rascunho]);
        Nota::factory()->create(['status' => StatusNota::DpsGerada]);
        Nota::factory()->create(['status' => StatusNota::Rejeitada]);
        Nota::factory()->create(['status' => StatusNota::Indeterminada]);
        Nota::factory()->create(['status' => StatusNota::Autorizada]);
        Nota::factory()->create(['status' => StatusNota::Cancelada]);

        $resumo = app(ResumoDeNotas::class);

        $this->assertSame(2, $resumo->emAberto(), 'Em aberto é rascunho e DPS gerada.');
        $this->assertSame(2, $resumo->precisamDeAtencao(), 'Pedem ação é rejeitada e indeterminada.');
        $this->assertSame(1, $resumo->quantidadePor(StatusNota::Autorizada));
        $this->assertSame(6, $resumo->total());
    }

    /**
     * O passo que falta é o próximo cadastro. A lista some inteira quando os
     * quatro estão prontos, e um passo a menos na lista deixaria alguém sem
     * certificado achando que já pode transmitir.
     */
    public function test_a_configuracao_lista_os_quatro_passos_na_ordem_do_cadastro(): void
    {
        $estado = app(EstadoDaConfiguracao::class);

        $this->assertSame(
            ['Municípios do IBGE', 'Empresa emitente', 'Certificado A1', 'Cliente tomador'],
            array_map(fn ($passo): string => $passo->titulo, $estado->passos()),
        );
    }

    public function test_a_configuracao_so_esta_completa_com_os_quatro_passos(): void
    {
        $estado = app(EstadoDaConfiguracao::class);

        $this->assertFalse($estado->estaCompleta(), 'Banco vazio não está configurado.');

        CidadeFactory::rio();
        Empresa::factory()->create();
        Cliente::factory()->create();

        $this->assertFalse($estado->estaCompleta(), 'Falta o certificado A1.');

        Empresa::factory()->comCertificado()->create();

        $this->assertTrue($estado->estaCompleta());
    }

    /**
     * O último número usado é o que impede o contador de voltar para trás no
     * cadastro. Sem empresa ou sem série não há sequência sobre a qual falar.
     */
    public function test_o_ultimo_numero_usado_e_por_empresa_e_por_serie(): void
    {
        $empresa = Empresa::factory()->create();
        Nota::factory()->create(['empresa_id' => $empresa->getKey(), 'serie' => '1', 'numero' => 7]);
        Nota::factory()->create(['empresa_id' => $empresa->getKey(), 'serie' => '1', 'numero' => 12]);
        Nota::factory()->create(['empresa_id' => $empresa->getKey(), 'serie' => 'A', 'numero' => 99]);

        $numeracao = app(NumeracaoDaEmpresa::class);

        $this->assertSame(12, $numeracao->ultimoNumeroUsado($empresa->getKey(), '1'));
        $this->assertSame(99, $numeracao->ultimoNumeroUsado($empresa->getKey(), 'A'));
        $this->assertNull($numeracao->ultimoNumeroUsado($empresa->getKey(), 'B'), 'Série nova começa do zero.');
        $this->assertNull($numeracao->ultimoNumeroUsado(null, '1'));
        $this->assertNull($numeracao->ultimoNumeroUsado($empresa->getKey(), null));
    }

    public function test_a_busca_de_cidades_acha_por_nome_e_por_codigo_ibge(): void
    {
        CidadeFactory::rio();
        Cidade::factory()->create(['codigo_ibge' => '3550308', 'nome' => 'São Paulo', 'uf' => 'SP']);

        $cidades = app(BuscaDeCidades::class);

        $this->assertSame(['Rio de Janeiro/RJ — 3304557'], array_values($cidades->procurar('Rio de Jan')));
        $this->assertSame(['São Paulo/SP — 3550308'], array_values($cidades->procurar('3550308')));
        $this->assertSame(2, $cidades->total());
    }

    public function test_a_busca_de_cidades_rotula_o_que_ja_foi_escolhido(): void
    {
        $rio = CidadeFactory::rio();
        $cidades = app(BuscaDeCidades::class);

        $this->assertSame('Rio de Janeiro/RJ — 3304557', $cidades->rotuloDe($rio->getKey()));
        $this->assertNull($cidades->rotuloDe(null));
        $this->assertNull($cidades->rotuloDe(999999), 'Id que não existe não vira rótulo inventado.');
        $this->assertSame($rio->getKey(), $cidades->idPeloCodigoIbge(CodigoIbge::deSeteDigitos('3304557')));
        $this->assertNull($cidades->idPeloCodigoIbge(CodigoIbge::deSeteDigitos('9999999')));
    }

    public function test_o_tomador_e_procurado_por_razao_social_e_por_documento(): void
    {
        $cliente = Cliente::factory()->create([
            'razao_social' => 'Exemplo Comércio S.A.',
            'cpf_cnpj' => '45543915000181',
        ]);

        $tomadores = app(ClientesTomadores::class);
        $rotulo = 'Exemplo Comércio S.A. — 45.543.915/0001-81';

        $this->assertSame([$rotulo], array_values($tomadores->procurar('Comércio')));
        $this->assertSame([$rotulo], array_values($tomadores->procurar('45543915')));
        $this->assertSame($rotulo, $tomadores->rotuloDe($cliente->getKey()));
        $this->assertNull($tomadores->rotuloDe(null));
        $this->assertNull($tomadores->rotuloDe(999999));
        $this->assertTrue($cliente->is($tomadores->encontrar($cliente->getKey())));
        $this->assertNull($tomadores->encontrar(null));
    }

    public function test_o_emitente_padrao_e_o_mais_antigo(): void
    {
        $primeira = Empresa::factory()->create(['razao_social' => 'Zeta LTDA']);
        Empresa::factory()->create(['razao_social' => 'Alfa LTDA']);

        $emitentes = app(EmpresasEmitentes::class);

        $this->assertTrue($primeira->is($emitentes->padrao()));
        $this->assertCount(2, $emitentes->paraSelecao());
        $this->assertStringStartsWith('Alfa LTDA — ', array_values($emitentes->paraSelecao())[0]);
        $this->assertNull($emitentes->encontrar(null));
    }

    /**
     * O que a nota herda do emitente ao escolhê-lo. Sem emitente não há padrão
     * nenhum a aplicar, e devolver metade dos campos apagaria o que o usuário
     * já digitou.
     */
    public function test_os_padroes_da_nota_saem_do_emitente(): void
    {
        $empresa = Empresa::factory()->create([
            'codigo_servico_padrao' => '140101',
            'cnae_padrao' => '9511800',
            'item_lista_servico_padrao' => '14.01',
            'aliquota_iss_padrao' => 3,
        ]);

        $padroes = app(PadroesDaEmpresa::class);

        $this->assertSame([
            'cidade_prestacao_id' => $empresa->cidade_id,
            'codigo_servico' => '140101',
            'cnae' => '9511800',
            'item_lista_servico' => '14.01',
            'nbs' => null,
            'aliquota_iss' => '3.0000',
            'cst_pis_cofins' => null,
            'aliquota_pis' => '0.0000',
            'aliquota_cofins' => '0.0000',
            'aliquota_csll' => '0.0000',
            'aliquota_irrf' => '0.0000',
            'aliquota_previdenciaria' => '0.0000',
            'cst_ibs_cbs' => null,
            'indicador_de_operacao' => null,
            'classificacao_tributaria' => null,
        ], $padroes->paraNota($empresa->getKey()));

        $this->assertSame([], $padroes->paraNota(null));
    }

    /**
     * O `LIKE` do SQLite so dobra caixa em ASCII, entao "SÃO PAULO" nao achava
     * nada enquanto "São Paulo" achava. E o seletor mais usado do sistema: o
     * municipio da prestacao, e o endereco do emitente e do tomador.
     */
    public function test_a_busca_de_cidades_acha_nome_acentuado_em_maiusculas(): void
    {
        Cidade::factory()->create(['nome' => 'São Paulo', 'uf' => 'SP', 'codigo_ibge' => '3550308']);

        $busca = app(BuscaDeCidades::class);

        $this->assertNotEmpty($busca->procurar('SÃO PAULO'));
        $this->assertNotEmpty($busca->procurar('são paulo'));
        $this->assertSame([], $busca->procurar('   '));
    }
}
