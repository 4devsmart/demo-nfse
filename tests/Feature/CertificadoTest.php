<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Empresas\GuardarCertificado;
use App\Fiscal\Certificado\LeitorDePfx;
use App\Fiscal\Excecoes\CertificadoInvalido;
use App\Models\Empresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;
use Tests\TestCase;

class CertificadoTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-do-pfx';

    private const TITULAR = 'DEMO LTDA:19131243000197';

    public function test_guarda_o_certificado_e_le_titular_e_validade(): void
    {
        $empresa = Empresa::factory()->create();

        $dados = app(GuardarCertificado::class)->executar($empresa, $this->pfx(), self::SENHA);

        $this->assertSame(self::TITULAR, $dados->titular);
        $this->assertFalse($dados->estaVencido());
        $this->assertTrue($empresa->refresh()->temCertificado());
    }

    public function test_o_pfx_e_a_senha_ficam_cifrados_no_banco(): void
    {
        $empresa = Empresa::factory()->create();

        app(GuardarCertificado::class)->executar($empresa, $this->pfx(), self::SENHA);

        $bruto = DB::table('empresas')->where('id', $empresa->getKey())->sole();

        $this->assertStringNotContainsString(self::SENHA, $bruto->certificado_senha);
        $this->assertNotSame(base64_encode($this->pfx()), $bruto->certificado_arquivo);
    }

    public function test_o_certificado_volta_intacto_para_a_chamada_da_api(): void
    {
        $empresa = Empresa::factory()->create();
        app(GuardarCertificado::class)->executar($empresa, $this->pfx(), self::SENHA);

        $corpo = $empresa->refresh()->certificado()->paraApi();

        $this->assertSame(self::SENHA, $corpo['senha']);
        $this->assertSame($this->pfx(), base64_decode($corpo['pfx_b64']));
    }

    /**
     * Senha errada e arquivo corrompido sao problemas diferentes, e a mensagem
     * tem que separa-los: a do OpenSSL diz qual dos dois foi. Sem isso, quem
     * digitou a senha errada le "nao foi possivel interpretar o certificado" e
     * vai procurar outro arquivo.
     */
    public function test_senha_errada_nao_passa_do_cadastro(): void
    {
        try {
            app(GuardarCertificado::class)->executar(Empresa::factory()->create(), $this->pfx(), 'errada');
            $this->fail('Senha errada não pode passar do cadastro.');
        } catch (CertificadoInvalido $falha) {
            $mensagem = $falha->getMessage();

            $this->assertStringStartsWith('Não foi possível abrir o certificado A1:', $mensagem);
            $this->assertStringNotContainsString(
                ':motivo',
                $mensagem,
                'O texto do OpenSSL tem que ter entrado no lugar do parâmetro.',
            );
            $this->assertStringNotContainsString('interpretar', $mensagem, 'O arquivo abriu; a senha é que não confere.');
            $this->assertStringNotContainsString(
                'senha incorreta ou arquivo corrompido.',
                $mensagem,
                'O OpenSSL disse o que houve: repassar isso vale mais que o palpite genérico.',
            );
        }
    }

    /**
     * Arquivo que nao e um .pfx tambem para no cadastro, e com o motivo do
     * OpenSSL junto: para conteudo que nao e ASN.1 ele reclama de "not enough
     * data", e isso distingue arquivo errado de senha errada.
     *
     * O palpite generico de `LeitorDePfx` fica para quando a fila de erros do
     * OpenSSL vier vazia, que nao acontece por este caminho.
     */
    public function test_arquivo_que_nao_e_certificado_nao_passa_do_cadastro(): void
    {
        try {
            app(GuardarCertificado::class)->executar(Empresa::factory()->create(), 'isto não é um pfx', 'seja lá qual');
            $this->fail('Arquivo que não é certificado não pode passar do cadastro.');
        } catch (CertificadoInvalido $falha) {
            $this->assertStringStartsWith('Não foi possível abrir o certificado A1:', $falha->getMessage());
            $this->assertStringContainsString('asn1 encoding routines', $falha->getMessage());
        }
    }

    /**
     * Um A1 vencido abre com a senha certa: `openssl_pkcs12_read` nao olha a
     * data. Quem tem que olhar e o cadastro, na emissao o custo do "nao"
     * passa a ser uma nota que nao sai.
     */
    public function test_certificado_vencido_nao_passa_do_cadastro(): void
    {
        $empresa = Empresa::factory()->create();
        $pfx = $this->pfx();

        // A data esperada sai do próprio certificado, e não do relógio do teste.
        // O `openssl_csr_sign` assina pelo relógio do sistema, que é UTC, e o
        // Carbon responde em America/Sao_Paulo: das 21h à meia-noite os dois
        // discordam de um dia, e a expectativa calculada quebrava toda noite.
        $validade = app(LeitorDePfx::class)->ler($pfx, self::SENHA)->validoAte->format('d/m/Y');

        // O A1 do teste vale um ano; o relogio anda dois.
        $this->travel(2)->years();

        $this->expectException(CertificadoInvalido::class);
        $this->expectExceptionMessage(
            'O certificado de '.self::TITULAR.' venceu em '.$validade.'. '
            .'A prefeitura recusaria a assinatura: renove o A1 antes de cadastrá-lo.'
        );

        try {
            app(GuardarCertificado::class)->executar($empresa, $pfx, self::SENHA);
        } finally {
            $this->assertFalse($empresa->refresh()->temCertificado(), 'Nada pode ter sido gravado.');
        }
    }

    public function test_empresa_sem_certificado_avisa_em_vez_de_devolver_lixo(): void
    {
        $empresa = Empresa::factory()->create();

        $this->expectException(CertificadoInvalido::class);
        // A razao social nomeada: com varios emitentes, "a empresa" nao diz qual.
        $this->expectExceptionMessage("A empresa {$empresa->razao_social} não tem certificado A1 cadastrado.");

        $empresa->certificado();
    }

    /**
     * Um A1 de mentira, gerado na hora: o teste nao depende de arquivo no repo.
     */
    private function pfx(): string
    {
        static $pfx = null;

        if ($pfx !== null) {
            return $pfx;
        }

        $chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $pedido = openssl_csr_new(['commonName' => self::TITULAR], $chave, ['digest_alg' => 'sha256']);
        $this->assertInstanceOf(OpenSSLCertificateSigningRequest::class, $pedido);

        $certificado = openssl_csr_sign($pedido, null, $chave, 365, ['digest_alg' => 'sha256']);
        $this->assertInstanceOf(OpenSSLCertificate::class, $certificado);

        openssl_pkcs12_export($certificado, $exportado, $chave, self::SENHA);

        return $pfx = $exportado;
    }
}
