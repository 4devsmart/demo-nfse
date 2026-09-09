<?php

declare(strict_types=1);

namespace App\Fiscal\Certificado;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * O A1 (.pfx) em base64 e a senha. A API fiscal nao persiste nada: o certificado
 * viaja na requisicao, e usado na sessao nativa e morre com ela. Guardar e
 * responsabilidade daqui.
 */
final readonly class CertificadoDigital
{
    private function __construct(
        private string $pfxEmBase64,
        #[SensitiveParameter] private string $senha,
    ) {}

    public static function deBase64(string $pfxEmBase64, #[SensitiveParameter] string $senha): self
    {
        if (trim($pfxEmBase64) === '') {
            throw new InvalidArgumentException(__('Certificado A1 vazio.'));
        }

        return new self(trim($pfxEmBase64), $senha);
    }

    public static function deConteudoBinario(string $conteudo, #[SensitiveParameter] string $senha): self
    {
        return self::deBase64(base64_encode($conteudo), $senha);
    }

    /**
     * @return array{pfx_b64: string, senha: string}
     */
    public function paraApi(): array
    {
        return [
            'pfx_b64' => $this->pfxEmBase64,
            'senha' => $this->senha,
        ];
    }

    public function emBase64(): string
    {
        return $this->pfxEmBase64;
    }
}
