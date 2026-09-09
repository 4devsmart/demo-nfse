<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Cidades\ImportarCidades;
use App\Actions\Notas\ReservarNumeroDaDps;
use App\Domain\Enums\Ambiente;
use App\Domain\Enums\RegimeEspecialTributacao;
use App\Domain\Enums\RegimeSimplesNacional;
use App\Fiscal\Traducao\ContextoDaEmpresa;
use App\Models\Cidade;
use App\Models\Empresa;
use Database\Factories\CidadeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Apoio\FonteDeCidadesFalsa;
use Tests\TestCase;

/**
 * O emitente é onde moram os dados que a API não guarda: o município que decide
 * o provedor, o regime tributário, a numeração e o certificado. Um cast errado
 * aqui não quebra nada visível, ele sai no XML.
 */
class CadastroDoEmitenteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * As colunas voltam do banco como texto. São os casts que devolvem enum,
     * inteiro e data, e é o enum que vira `opSimpNac` e `regEspTrib` na DPS.
     */
    public function test_as_colunas_voltam_do_banco_com_o_tipo_do_dominio(): void
    {
        $empresa = Empresa::factory()->comCertificado()->create([
            'ambiente' => Ambiente::Producao,
            'regime_simples_nacional' => RegimeSimplesNacional::OptanteMei,
            'regime_especial' => RegimeEspecialTributacao::SociedadeDeProfissionais,
            'proximo_numero_dps' => '7',
            'aliquota_iss_padrao' => 2.5,
            'certificado_valido_ate' => '2027-03-15',
        ])->fresh();

        $this->assertNotNull($empresa);
        $this->assertSame(Ambiente::Producao, $empresa->ambiente);
        $this->assertSame(RegimeSimplesNacional::OptanteMei, $empresa->regime_simples_nacional);
        $this->assertSame(RegimeEspecialTributacao::SociedadeDeProfissionais, $empresa->regime_especial);
        $this->assertSame(7, $empresa->proximo_numero_dps);
        $this->assertSame('2.5000', $empresa->aliquota_iss_padrao);
        $this->assertInstanceOf(Carbon::class, $empresa->certificado_valido_ate);
        $this->assertSame('15/03/2027', $empresa->certificado_valido_ate->format('d/m/Y'));

        $this->assertSame(
            ['opSimpNac' => 2, 'regApTribSN' => null, 'regEspTrib' => 6],
            $empresa->comoPrestador()->paraApi()['regTrib'],
        );
    }

    /**
     * O prazo do certificado é contado em dias, e o sinal é o que importa:
     * negativo quer dizer vencido. Contar em valor absoluto faria um A1 vencido
     * ontem parecer válido por mais um dia.
     */
    public function test_o_prazo_do_certificado_e_negativo_depois_de_vencer(): void
    {
        $semCertificado = Empresa::factory()->create();
        $emDia = Empresa::factory()->comCertificado()->create(['certificado_valido_ate' => now()->addDays(45)]);
        $vencida = Empresa::factory()->comCertificado()->create(['certificado_valido_ate' => now()->subDays(3)]);

        $this->assertNull($semCertificado->diasAteOCertificadoVencer());
        $this->assertSame(45, $emDia->diasAteOCertificadoVencer());
        $this->assertSame(-3, $vencida->diasAteOCertificadoVencer());
    }

    /**
     * O ambiente da chamada é o da NOTA, não o do cadastro: o emitente pode ter
     * mudado para produção depois de a nota nascer em homologação, e transmitir
     * uma nota de homologação como se fosse de produção é documento fiscal
     * criado por engano.
     */
    public function test_o_contexto_usa_o_ambiente_pedido_e_nao_o_do_cadastro(): void
    {
        $empresa = Empresa::factory()->comCertificado()->create(['ambiente' => Ambiente::Homologacao]);
        $contexto = app(ContextoDaEmpresa::class);

        $this->assertSame('homologacao', $contexto->montar($empresa)->paraApi()['ambiente']);
        $this->assertSame('producao', $contexto->montar($empresa, Ambiente::Producao)->paraApi()['ambiente']);
    }

    /**
     * A reserva devolve o número e deixa a instância em mãos já apontando para
     * o próximo: quem reservar duas vezes na mesma requisição, a substituição
     * faz isso, não pode receber o mesmo número duas vezes.
     */
    public function test_a_reserva_deixa_a_instancia_apontando_para_o_proximo(): void
    {
        $empresa = Empresa::factory()->create(['proximo_numero_dps' => 10]);
        $reservar = app(ReservarNumeroDaDps::class);

        $this->assertSame(10, $reservar->executar($empresa));
        $this->assertSame(11, $empresa->proximo_numero_dps, 'A instância em memória acompanha o banco.');
        $this->assertSame(11, $reservar->executar($empresa));
    }

    /**
     * A carga do IBGE é um upsert pelo código: município que já existe tem nome
     * e UF atualizados em vez de duplicar, e o carimbo de tempo entra junto:
     * sem ele o `created_at` fica nulo numa coluna que não aceita.
     */
    public function test_a_carga_de_municipios_atualiza_em_vez_de_duplicar(): void
    {
        CidadeFactory::rio();

        $total = new ImportarCidades(new FonteDeCidadesFalsa([
            ['codigo_ibge' => '3304557', 'nome' => 'Rio de Janeiro (novo nome)', 'uf' => 'RJ'],
            ['codigo_ibge' => '3550308', 'nome' => 'São Paulo', 'uf' => 'SP'],
        ]))->executar();

        $this->assertSame(2, $total);
        $this->assertSame(2, Cidade::query()->count());

        $rio = Cidade::query()->where('codigo_ibge', '3304557')->sole();
        $novo = Cidade::query()->where('codigo_ibge', '3550308')->sole();

        $this->assertSame('Rio de Janeiro (novo nome)', $rio->nome);
        $this->assertNotNull($novo->created_at);
        $this->assertNotNull($novo->updated_at);
    }
}
