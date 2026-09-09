<?php

declare(strict_types=1);

namespace App\Fiscal\Excecoes;

use RuntimeException;

final class CertificadoInvalido extends RuntimeException
{
    public static function naoAbriu(string $motivo): self
    {
        return new self(__('Não foi possível abrir o certificado A1: :motivo', ['motivo' => $motivo]));
    }

    public static function ausente(string $empresa): self
    {
        return new self(__('A empresa :empresa não tem certificado A1 cadastrado.', ['empresa' => $empresa]));
    }

    public static function vencido(string $titular, string $validoAte): self
    {
        return new self(__(
            'O certificado de :titular venceu em :validade. '
            .'A prefeitura recusaria a assinatura: renove o A1 antes de cadastrá-lo.',
            ['titular' => $titular, 'validade' => $validoAte],
        ));
    }
}
