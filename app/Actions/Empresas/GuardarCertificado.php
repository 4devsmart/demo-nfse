<?php

declare(strict_types=1);

namespace App\Actions\Empresas;

use App\Fiscal\Certificado\CertificadoDigital;
use App\Fiscal\Certificado\DadosDoCertificado;
use App\Fiscal\Certificado\LeitorDePfx;
use App\Fiscal\Excecoes\CertificadoInvalido;
use App\Models\Empresa;
use SensitiveParameter;

/**
 * Confere o A1 antes de guardar: a senha, e tambem a validade. Certificado que
 * nao serve aqui nao serviria na prefeitura, e descobrir isso no cadastro custa
 * um aviso, descobrir na emissao custa uma nota que nao sai.
 */
final readonly class GuardarCertificado
{
    public function __construct(private LeitorDePfx $leitor) {}

    public function executar(Empresa $empresa, string $conteudoDoPfx, #[SensitiveParameter] string $senha): DadosDoCertificado
    {
        $dados = $this->leitor->ler($conteudoDoPfx, $senha);

        if ($dados->estaVencido()) {
            throw CertificadoInvalido::vencido($dados->titular, $dados->validoAte->format('d/m/Y'));
        }

        $certificado = CertificadoDigital::deConteudoBinario($conteudoDoPfx, $senha);

        $empresa->forceFill([
            'certificado_arquivo' => $certificado->emBase64(),
            'certificado_senha' => $senha,
            'certificado_titular' => $dados->titular,
            'certificado_valido_ate' => $dados->validoAte,
        ])->save();

        return $dados;
    }
}
