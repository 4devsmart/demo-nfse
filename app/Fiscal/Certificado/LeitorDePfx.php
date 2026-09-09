<?php

declare(strict_types=1);

namespace App\Fiscal\Certificado;

use App\Fiscal\Excecoes\CertificadoInvalido;
use Illuminate\Support\Carbon;
use SensitiveParameter;

/**
 * Le o .pfx localmente so para conferir a senha e mostrar validade e titular na
 * tela. Nao e validacao fiscal: quem decide se o certificado serve e a prefeitura.
 */
final class LeitorDePfx
{
    public function ler(string $conteudoBinario, #[SensitiveParameter] string $senha): DadosDoCertificado
    {
        $certificado = [];

        if (! openssl_pkcs12_read($conteudoBinario, $certificado, $senha)) {
            throw CertificadoInvalido::naoAbriu($this->ultimoErroDoOpenSsl());
        }

        $lido = openssl_x509_parse($certificado['cert'] ?? '');

        if ($lido === false) {
            throw CertificadoInvalido::naoAbriu(__('não foi possível interpretar o certificado do arquivo.'));
        }

        return new DadosDoCertificado(
            titular: $this->titularDe($lido),
            validoAte: Carbon::createFromTimestampUTC($lido['validTo_time_t'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>  $certificadoLido
     */
    private function titularDe(array $certificadoLido): string
    {
        $assunto = $certificadoLido['subject'] ?? [];

        if (! is_array($assunto)) {
            return __('desconhecido');
        }

        return (string) ($assunto['CN'] ?? __('desconhecido'));
    }

    private function ultimoErroDoOpenSsl(): string
    {
        $mensagens = [];

        while (($erro = openssl_error_string()) !== false) {
            $mensagens[] = $erro;
        }

        if ($mensagens === []) {
            return __('senha incorreta ou arquivo corrompido.');
        }

        return implode(' | ', $mensagens);
    }
}
