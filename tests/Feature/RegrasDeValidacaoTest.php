<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\Notas\Pages\EditNota;
use App\Models\Nota;
use App\Models\User;
use App\Rules\CodigoIbgeValido;
use App\Rules\DocumentoFederalValido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * As duas regras que recusam no cadastro o que a prefeitura recusaria depois.
 *
 * A mensagem faz parte da regra: quem digita um CPF errado precisa saber que é
 * o CPF, e não "o campo é inválido". Testar só o booleano deixaria passar uma
 * regra que reprova pelo motivo certo e explica o errado.
 */
class RegrasDeValidacaoTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('documentosInvalidos')]
    public function test_documento_invalido_e_recusado_com_a_mensagem_certa(mixed $valor): void
    {
        $validacao = Validator::make(['cpf_cnpj' => $valor], ['cpf_cnpj' => [new DocumentoFederalValido]]);

        $this->assertTrue($validacao->fails());
        $this->assertSame('Informe um CPF ou CNPJ válido.', $validacao->errors()->first('cpf_cnpj'));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function documentosInvalidos(): array
    {
        return [
            'dígito verificador errado' => ['52998224726'],
            'todos os dígitos iguais' => ['11111111111'],
            'tamanho que não é CPF nem CNPJ' => ['123'],
            'não é texto' => [123],
        ];
    }

    /**
     * Campo em branco e assunto do `required`, nao desta regra: o Laravel nem
     * chega a chama-la para valor vazio. Deixar isso escrito evita alguem
     * apagar o `required()` do formulario achando que a regra cobre os dois.
     */
    public function test_campo_em_branco_e_assunto_do_required(): void
    {
        $soARegra = Validator::make(['cpf_cnpj' => ''], ['cpf_cnpj' => [new DocumentoFederalValido]]);
        $comRequired = Validator::make(['cpf_cnpj' => ''], ['cpf_cnpj' => ['required', new DocumentoFederalValido]]);

        $this->assertFalse($soARegra->fails());
        $this->assertTrue($comRequired->fails());
    }

    #[DataProvider('documentosValidos')]
    public function test_documento_valido_passa_com_ou_sem_mascara(string $valor): void
    {
        $validacao = Validator::make(['cpf_cnpj' => $valor], ['cpf_cnpj' => [new DocumentoFederalValido]]);

        $this->assertFalse($validacao->fails(), "Recusou {$valor}.");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function documentosValidos(): array
    {
        return [
            'CPF cru' => ['52998224725'],
            'CPF com máscara' => ['529.982.247-25'],
            'CNPJ cru' => ['19131243000197'],
            'CNPJ com máscara' => ['19.131.243/0001-97'],
        ];
    }

    /**
     * É o código IBGE que decide o provedor de NFS-e. Um dígito a menos não é
     * um município: é uma consulta que a API responde com
     * `provedor_nao_suportado` depois.
     */
    /**
     * As regras dos campos mascarados. Elas existem para o que a máscara do
     * navegador não impede: valor colado de uma planilha, campo preenchido por
     * código, JavaScript desligado.
     *
     * A mensagem é o que se testa, pelo mesmo motivo do documento acima. "O
     * campo é inválido" não diz a quem está com a nota parada o que digitar.
     */
    #[DataProvider('valoresQueAsRegrasRecusam')]
    public function test_campo_mascarado_recusado_explica_o_que_digitar(
        string $campo,
        string|int $valor,
        string $mensagem,
    ): void {
        $this->actingAs(User::factory()->create());
        $nota = Nota::factory()->create();

        Livewire::test(EditNota::class, ['record' => $nota->getKey()])
            ->fillForm([$campo => $valor])
            ->call('save')
            ->assertHasFormErrors([$campo => $mensagem]);
    }

    /**
     * O outro lado da mesma regra, como no documento acima: campo em branco é
     * assunto do `required()`. As regras do campo mascarado não podem opinar
     * sobre vazio, ou o formulário passaria a dar duas explicações para a mesma
     * lacuna.
     */
    public function test_campo_mascarado_em_branco_e_assunto_do_required(): void
    {
        $this->actingAs(User::factory()->create());
        $nota = Nota::factory()->create();

        $componente = Livewire::test(EditNota::class, ['record' => $nota->getKey()])
            ->fillForm(['valor_servico' => '', 'aliquota_iss' => ''])
            ->call('save');

        foreach (['data.valor_servico', 'data.aliquota_iss'] as $campo) {
            $mensagens = $componente->errors()->get($campo);

            $this->assertCount(1, $mensagens, "O campo {$campo} em branco deu duas explicações para a mesma lacuna.");
            $this->assertStringContainsString('obrigatório', $mensagens[0]);
        }
    }

    /**
     * @return array<string, array{string, string|int, string}>
     */
    public static function valoresQueAsRegrasRecusam(): array
    {
        return [
            'dinheiro que não é número' => ['valor_servico', 'mil e quinhentos', 'Informe um valor como 1.500,50.'],
            'serviço de graça' => ['valor_servico', '0,00', 'O valor do serviço precisa ser maior que zero.'],
            'percentual que não é número' => ['aliquota_iss', 'cinco', 'Informe um percentual como 5 ou 2,75.'],
            'alíquota acima de cem' => ['aliquota_iss', '150', 'O percentual não pode passar de 100.'],
        ];
    }

    public function test_o_codigo_ibge_tem_sete_digitos_ou_nao_existe(): void
    {
        $curto = Validator::make(['codigo_ibge' => '330455'], ['codigo_ibge' => [new CodigoIbgeValido]]);
        $certo = Validator::make(['codigo_ibge' => '3304557'], ['codigo_ibge' => [new CodigoIbgeValido]]);

        $this->assertTrue($curto->fails());
        $this->assertSame('O código IBGE precisa ter 7 dígitos.', $curto->errors()->first('codigo_ibge'));
        $this->assertFalse($certo->fails());
    }
}
