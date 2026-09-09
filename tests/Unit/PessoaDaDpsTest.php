<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Enums\RegimeEspecialTributacao;
use App\Domain\Enums\RegimeSimplesNacional;
use App\Domain\ValueObjects\CodigoIbge;
use App\Domain\ValueObjects\DocumentoFederal;
use App\Fiscal\Dps\Endereco;
use App\Fiscal\Dps\Pessoa;
use App\Fiscal\Dps\RegimeTributario;
use PHPUnit\Framework\TestCase;

/**
 * `infDPS.prest` e `infDPS.toma`, campo a campo.
 *
 * A assercao e sobre o array inteiro, e nao sobre uma chave de cada vez, de
 * proposito: e o que faz um campo trocado de nome, ou sumido, quebrar o
 * teste. Aqui a diferenca entre `email` e `IM` nao aparece em lugar nenhum
 * ate a prefeitura autorizar a nota com o dado no campo errado.
 */
class PessoaDaDpsTest extends TestCase
{
    public function test_o_prestador_leva_documento_contato_regime_e_endereco(): void
    {
        $prestador = Pessoa::identificadaPor(DocumentoFederal::deCpfOuCnpj('19.131.243/0001-97'), 'Demo LTDA')
            ->comInscricaoMunicipal('1234567')
            ->comContato('fiscal@demo.test', '(21) 3333-4444')
            ->em($this->endereco())
            ->sobRegime(new RegimeTributario(
                RegimeSimplesNacional::OptanteMei,
                RegimeEspecialTributacao::Estimativa,
            ));

        $this->assertSame([
            'CNPJ' => '19131243000197',
            'xNome' => 'Demo LTDA',
            'IM' => '1234567',
            'email' => 'fiscal@demo.test',
            'telefone' => '2133334444',
            'regTrib' => ['opSimpNac' => 2, 'regApTribSN' => null, 'regEspTrib' => 2],
            'cMun' => '3304557',
            'UF' => 'RJ',
            'CEP' => '20040020',
            'logradouro' => 'Rua da Assembleia',
            'numero' => '10',
            'complemento' => 'sala 1',
            'bairro' => 'Centro',
        ], $prestador->paraApi());
    }

    /**
     * O tomador comum nao tem regime nem inscricao municipal. Os campos ficam
     * vazios aqui e e o ConstrutorDps que os poda, quem inventasse um valor
     * neste ponto mandaria dado falso para o XML.
     */
    public function test_pessoa_sem_endereco_nem_regime_nao_inventa_grupo(): void
    {
        $tomador = Pessoa::identificadaPor(DocumentoFederal::deCpfOuCnpj('529.982.247-25'), 'Fulano de Tal');

        $this->assertSame([
            'CPF' => '52998224725',
            'xNome' => 'Fulano de Tal',
            'IM' => '',
            'email' => '',
            'telefone' => '',
            'regTrib' => null,
        ], $tomador->paraApi());
    }

    /**
     * Cada `com*` devolve instancia nova, entao a ultima palavra e a de quem
     * chamou por ultimo. Sem esta assercao, um construtor que ignorasse o
     * segundo valor passaria batido, e o cadastro corrigido na tela sairia
     * na DPS com o dado antigo.
     */
    public function test_o_dado_novo_substitui_o_anterior(): void
    {
        $pessoa = Pessoa::identificadaPor(DocumentoFederal::deCpfOuCnpj('19131243000197'), 'Demo LTDA')
            ->comInscricaoMunicipal('111')
            ->comContato('velho@demo.test', '(21) 1111-1111')
            ->em($this->endereco())
            ->sobRegime(new RegimeTributario(
                RegimeSimplesNacional::NaoOptante,
                RegimeEspecialTributacao::Nenhum,
            ))
            ->comInscricaoMunicipal('222')
            ->comContato('novo@demo.test', '(21) 2222-2222')
            ->em($this->outroEndereco())
            ->sobRegime(new RegimeTributario(
                RegimeSimplesNacional::OptanteMicroEmpresa,
                RegimeEspecialTributacao::SociedadeDeProfissionais,
            ));

        $corpo = $pessoa->paraApi();

        $this->assertSame('222', $corpo['IM']);
        $this->assertSame('novo@demo.test', $corpo['email']);
        $this->assertSame('2122222222', $corpo['telefone']);
        $this->assertSame(['opSimpNac' => 3, 'regApTribSN' => null, 'regEspTrib' => 6], $corpo['regTrib']);
        $this->assertSame('3550308', $corpo['cMun']);
        $this->assertSame('SP', $corpo['UF']);
    }

    /**
     * A UF sai em maiuscula e o CEP so com digitos: os dois viajam como texto,
     * e o provedor recusa o formato que nao reconhece.
     */
    public function test_o_endereco_normaliza_a_uf_e_o_cep(): void
    {
        $endereco = new Endereco(
            municipio: CodigoIbge::deSeteDigitos('3304557'),
            uf: 'rj',
            cep: '20.040-020',
            logradouro: 'Rua da Assembleia',
            numero: '10',
            bairro: 'Centro',
        );

        $this->assertSame([
            'cMun' => '3304557',
            'UF' => 'RJ',
            'CEP' => '20040020',
            'logradouro' => 'Rua da Assembleia',
            'numero' => '10',
            'complemento' => '',
            'bairro' => 'Centro',
        ], $endereco->paraApi());
    }

    private function endereco(): Endereco
    {
        return new Endereco(
            municipio: CodigoIbge::deSeteDigitos('3304557'),
            uf: 'RJ',
            cep: '20040-020',
            logradouro: 'Rua da Assembleia',
            numero: '10',
            bairro: 'Centro',
            complemento: 'sala 1',
        );
    }

    private function outroEndereco(): Endereco
    {
        return new Endereco(
            municipio: CodigoIbge::deSeteDigitos('3550308'),
            uf: 'SP',
            cep: '01310-100',
            logradouro: 'Avenida Paulista',
            numero: '1000',
            bairro: 'Bela Vista',
        );
    }
}
