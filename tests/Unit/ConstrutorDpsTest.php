<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Enums\Ambiente;
use App\Domain\Enums\RegimeEspecialTributacao;
use App\Domain\Enums\RegimeSimplesNacional;
use App\Domain\ValueObjects\Aliquota;
use App\Domain\ValueObjects\CodigoIbge;
use App\Domain\ValueObjects\Competencia;
use App\Domain\ValueObjects\Dinheiro;
use App\Domain\ValueObjects\DocumentoFederal;
use App\Fiscal\Dps\ConstrutorDps;
use App\Fiscal\Dps\Endereco;
use App\Fiscal\Dps\Pessoa;
use App\Fiscal\Dps\RegimeTributario;
use App\Fiscal\Dps\ServicoPrestado;
use App\Fiscal\Dps\ValoresDoServico;
use LogicException;
use Tests\TestCase;

/**
 * Sobe o Laravel, ao contrario dos demais testes de `tests/Unit`: as mensagens
 * passam por `__()`, e o helper precisa do container.
 */
class ConstrutorDpsTest extends TestCase
{
    private const MUNICIPIO = '3304557';

    private const OUTRO_MUNICIPIO = '3550308';

    public function test_monta_o_corpo_que_a_api_espera(): void
    {
        $corpo = $this->construtorCompleto()->montar()->paraApi();

        $this->assertSame('homologacao', $corpo['ambiente']);
        $this->assertSame('REF-1', $corpo['referencia']);

        $dps = $corpo['infDPS'];
        $this->assertSame('1', $dps['serie']);
        $this->assertSame('42', $dps['nDPS']);
        $this->assertSame(1, $dps['tpEmit']);
        $this->assertSame(self::MUNICIPIO, $dps['cLocEmi']);
        $this->assertSame('2026-09-01', $dps['dCompet']);

        $this->assertSame('19131243000197', $dps['prest']['CNPJ']);
        $this->assertSame(['opSimpNac' => 1, 'regEspTrib' => 0], $dps['prest']['regTrib']);
        $this->assertSame('45543915000181', $dps['toma']['CNPJ']);
        $this->assertSame('010701', $dps['serv']['cServ']);
        $this->assertSame(1500.5, $dps['valores']['vServ']);
    }

    public function test_pessoa_fisica_vai_como_cpf(): void
    {
        $tomador = Pessoa::identificadaPor(DocumentoFederal::deCpfOuCnpj('52998224725'), 'Fulano')
            ->em($this->endereco());

        $dps = $this->construtorCompleto()->para($tomador)->montar()->paraApi()['infDPS'];

        $this->assertSame('52998224725', $dps['toma']['CPF']);
        $this->assertArrayNotHasKey('CNPJ', $dps['toma']);
    }

    public function test_campos_vazios_sao_podados_antes_de_sair(): void
    {
        $dps = $this->construtorCompleto()->montar()->paraApi()['infDPS'];

        // O tomador nao tem regime tributario nem inscricao municipal: nem um
        // nem outro pode ir como string vazia para o XML.
        $this->assertArrayNotHasKey('regTrib', $dps['toma']);
        $this->assertArrayNotHasKey('IM', $dps['toma']);
        $this->assertArrayNotHasKey('codigoCnae', $dps['serv']);
    }

    public function test_recusa_montar_uma_dps_incompleta(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('DPS incompleta');

        ConstrutorDps::novo()->noAmbiente(Ambiente::Homologacao)->montar();
    }

    /**
     * Preenchida nao e coerente. Os valores viajam separados (`vServ`,
     * `vDeducoes`, `vDescIncond`), entao quem faria a conta e o provedor, ja
     * com o documento na mao. A DPS nao chega a existir.
     */
    public function test_recusa_montar_com_abatimentos_maiores_que_o_servico(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('base de cálculo ficaria negativa');

        $this->construtorCompleto()
            ->comValores(
                ValoresDoServico::cobrados(Dinheiro::deReais(100), Aliquota::deQuatroCasas(5))
                    ->comDeducoes(Dinheiro::deReais(1000))
            )
            ->montar();
    }

    public function test_cada_passo_devolve_uma_instancia_nova(): void
    {
        $base = ConstrutorDps::novo();

        $this->assertNotSame($base, $base->noAmbiente(Ambiente::Producao));
    }

    /**
     * A ultima palavra e a de quem chamou por ultimo. Sem esta assercao, um
     * construtor que ignorasse o segundo valor passaria batido, e a nota
     * corrigida na tela sairia com o dado antigo.
     */
    public function test_repetir_um_passo_troca_o_valor_anterior(): void
    {
        $dps = $this->construtorCompleto()
            ->noAmbiente(Ambiente::Producao)
            ->emitidaPor($this->outroPrestador())
            ->naCidade(CodigoIbge::deSeteDigitos(self::OUTRO_MUNICIPIO))
            ->naCompetencia(Competencia::noMesDe('2026-03-10'))
            ->doServico(ServicoPrestado::prestadoEm(
                CodigoIbge::deSeteDigitos(self::OUTRO_MUNICIPIO),
                '140101',
                'Manutenção',
            ))
            ->montar()
            ->paraApi();

        $this->assertSame('producao', $dps['ambiente']);
        $this->assertSame('45543915000181', $dps['infDPS']['prest']['CNPJ']);
        $this->assertSame(self::OUTRO_MUNICIPIO, $dps['infDPS']['cLocEmi']);
        $this->assertSame('2026-03-01', $dps['infDPS']['dCompet']);
        $this->assertSame('140101', $dps['infDPS']['serv']['cServ']);
    }

    /**
     * A mensagem lista TUDO o que falta de uma vez, e nao o primeiro campo que
     * apareceu. Quem monta uma DPS a mao descobre em uma tentativa o que
     * descobriria em oito.
     */
    public function test_a_recusa_nomeia_todos_os_campos_que_faltam(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'DPS incompleta, falta: ambiente, prestador, tomador, municipio emissor, '
            .'serviço, valores, competência, número.'
        );

        ConstrutorDps::novo()->montar();
    }

    public function test_a_recusa_por_incoerencia_explica_a_conta(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Deduções e desconto incondicionado somam mais que o valor do serviço: '
            .'a base de cálculo ficaria negativa.'
        );

        $this->construtorCompleto()
            ->comValores(
                ValoresDoServico::cobrados(Dinheiro::deReais(100), Aliquota::deQuatroCasas(5))
                    ->comDescontos(Dinheiro::deReais(101), Dinheiro::zero())
            )
            ->montar();
    }

    /**
     * A poda salta o campo vazio e segue: ela nao para no primeiro. O tomador
     * sem inscricao municipal e o caso comum, e ele vem antes do endereco na
     * mesma lista, parar ali mandaria a DPS sem endereco nenhum.
     */
    public function test_a_poda_de_campo_vazio_nao_interrompe_o_resto(): void
    {
        // Sem `identificadaPor`: a referencia fica vazia e e podada no topo,
        // logo antes do grupo que carrega a nota inteira.
        $corpo = $this->construtorCompleto()->identificadaPor('')->montar()->paraApi();

        $this->assertArrayNotHasKey('referencia', $corpo);
        $this->assertArrayHasKey('infDPS', $corpo);

        $tomador = $corpo['infDPS']['toma'];
        $this->assertArrayNotHasKey('IM', $tomador);
        $this->assertSame(self::MUNICIPIO, $tomador['cMun']);
        $this->assertSame('Centro', $tomador['bairro']);
    }

    private function outroPrestador(): Pessoa
    {
        return Pessoa::identificadaPor(DocumentoFederal::deCpfOuCnpj('45543915000181'), 'Outra LTDA')
            ->em($this->endereco())
            ->sobRegime(new RegimeTributario(
                RegimeSimplesNacional::OptanteMei,
                RegimeEspecialTributacao::Nenhum,
            ));
    }

    private function construtorCompleto(): ConstrutorDps
    {
        return ConstrutorDps::novo()
            ->noAmbiente(Ambiente::Homologacao)
            ->emitidaPor($this->prestador())
            ->naCidade(CodigoIbge::deSeteDigitos(self::MUNICIPIO))
            ->para($this->tomador())
            ->numerada('1', '42')
            ->naCompetencia(Competencia::noMesDe('2026-09-15'))
            ->doServico(ServicoPrestado::prestadoEm(
                CodigoIbge::deSeteDigitos(self::MUNICIPIO),
                '010701',
                'Desenvolvimento de software',
            ))
            ->comValores(ValoresDoServico::cobrados(Dinheiro::deReais(1500.5), Aliquota::deQuatroCasas(5)))
            ->identificadaPor('REF-1');
    }

    private function prestador(): Pessoa
    {
        return Pessoa::identificadaPor(DocumentoFederal::deCpfOuCnpj('19131243000197'), 'Demo LTDA')
            ->comInscricaoMunicipal('1234567')
            ->comContato('fiscal@demo.test', '(21) 3333-4444')
            ->em($this->endereco())
            ->sobRegime(new RegimeTributario(
                RegimeSimplesNacional::NaoOptante,
                RegimeEspecialTributacao::Nenhum,
            ));
    }

    private function tomador(): Pessoa
    {
        return Pessoa::identificadaPor(DocumentoFederal::deCpfOuCnpj('45543915000181'), 'Exemplo S.A.')
            ->em($this->endereco());
    }

    private function endereco(): Endereco
    {
        return new Endereco(
            municipio: CodigoIbge::deSeteDigitos(self::MUNICIPIO),
            uf: 'RJ',
            cep: '20040-020',
            logradouro: 'Rua da Assembleia',
            numero: '10',
            bairro: 'Centro',
        );
    }
}
