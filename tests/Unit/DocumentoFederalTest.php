<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Enums\TipoPessoa;
use App\Domain\ValueObjects\DocumentoFederal;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DocumentoFederalTest extends TestCase
{
    public function test_aceita_cnpj_com_mascara_e_guarda_so_os_digitos(): void
    {
        $documento = DocumentoFederal::deCpfOuCnpj('19.131.243/0001-97');

        $this->assertSame('19131243000197', $documento->digitos);
        $this->assertSame(TipoPessoa::Juridica, $documento->tipo);
        $this->assertTrue($documento->ehCnpj());
        $this->assertSame('19.131.243/0001-97', $documento->formatado());
    }

    public function test_aceita_cpf_e_o_formata(): void
    {
        $documento = DocumentoFederal::deCpfOuCnpj('52998224725');

        $this->assertSame(TipoPessoa::Fisica, $documento->tipo);
        $this->assertFalse($documento->ehCnpj());
        $this->assertSame('529.982.247-25', $documento->formatado());
    }

    #[DataProvider('documentosInvalidos')]
    public function test_recusa_documento_invalido(string $valor): void
    {
        $this->expectException(InvalidArgumentException::class);

        DocumentoFederal::deCpfOuCnpj($valor);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function documentosInvalidos(): array
    {
        return [
            'digito verificador errado' => ['19131243000198'],
            'todos os digitos iguais' => ['11111111111'],
            'tamanho invalido' => ['123456'],
            'vazio' => [''],
        ];
    }

    /**
     * O formulario hidrata o campo com o que esta na coluna, que e so digito, e
     * e dai que sai a escrita que a mascara do navegador confirma. Documento
     * pela metade volta como veio: recusar e assunto da validacao, na gravacao.
     */
    public function test_mascara_o_texto_solto_pelo_tamanho(): void
    {
        $this->assertSame('45.543.915/0001-81', DocumentoFederal::mascarado('45543915000181'));
        $this->assertSame('529.982.247-25', DocumentoFederal::mascarado('52998224725'));
        $this->assertSame('45.543.915/0001-81', DocumentoFederal::mascarado('45.543.915/0001-81'));
        $this->assertSame('455439150', DocumentoFederal::mascarado('455439150'));
        $this->assertSame('', DocumentoFederal::mascarado(''));
    }

    public function test_eh_valido_nao_lanca(): void
    {
        $this->assertTrue(DocumentoFederal::ehValido('45.543.915/0001-81'));
        $this->assertFalse(DocumentoFederal::ehValido('45543915000182'));
    }

    /**
     * Uma amostra em vez de um exemplo. Os pesos do digito verificador do CNPJ
     * sao ciclicos (6,5,4,3,2,9,8,7,…), e trocar um deles ainda deixa passar
     * a maioria dos documentos: e preciso mais de um numero para que a conta
     * errada apareca. Documento invalido aceito no cadastro so aparece quando a
     * prefeitura recusa a nota.
     */
    #[DataProvider('documentosValidos')]
    public function test_aceita_documento_com_digito_verificador_correto(string $valor, TipoPessoa $tipo): void
    {
        $documento = DocumentoFederal::deCpfOuCnpj($valor);

        $this->assertSame($valor, $documento->digitos);
        $this->assertSame($tipo, $documento->tipo);
    }

    /**
     * @return array<string, array{string, TipoPessoa}>
     */
    public static function documentosValidos(): array
    {
        return [
            'CNPJ 11222333000181' => ['11222333000181', TipoPessoa::Juridica],
            'CNPJ 00998877000113' => ['00998877000113', TipoPessoa::Juridica],
            'CNPJ 34567890000130' => ['34567890000130', TipoPessoa::Juridica],
            'CNPJ 87000456000130' => ['87000456000130', TipoPessoa::Juridica],
            'CNPJ 19131243000197' => ['19131243000197', TipoPessoa::Juridica],
            'CNPJ 45543915000181' => ['45543915000181', TipoPessoa::Juridica],
            'CPF 12345678909' => ['12345678909', TipoPessoa::Fisica],
            'CPF 98765432100' => ['98765432100', TipoPessoa::Fisica],
            'CPF 11122233396' => ['11122233396', TipoPessoa::Fisica],
            'CPF 52998224725' => ['52998224725', TipoPessoa::Fisica],
        ];
    }

    /**
     * Cada digito verificador errado, um de cada vez: o primeiro sozinho, o
     * segundo sozinho. Conferir so a soma dos dois deixaria passar a troca de
     * um pelo outro.
     */
    #[DataProvider('documentosComDigitoTrocado')]
    public function test_recusa_quando_um_unico_digito_verificador_muda(string $valor): void
    {
        $this->assertFalse(DocumentoFederal::ehValido($valor));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function documentosComDigitoTrocado(): array
    {
        return [
            'CNPJ com o primeiro DV errado' => ['11222333000191'],
            'CNPJ com o segundo DV errado' => ['11222333000182'],
            'CPF com o primeiro DV errado' => ['12345678919'],
            'CPF com o segundo DV errado' => ['12345678908'],
        ];
    }
}
