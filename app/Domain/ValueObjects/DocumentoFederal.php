<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Enums\TipoPessoa;
use InvalidArgumentException;
use Stringable;

/**
 * CPF ou CNPJ. Nasce validado: se existe uma instancia, os digitos verificadores
 * conferem. A API fiscal aceita com ou sem mascara, mas guardamos so os digitos.
 */
final readonly class DocumentoFederal implements Stringable
{
    private function __construct(
        public string $digitos,
        public TipoPessoa $tipo,
    ) {}

    public static function deCpfOuCnpj(string $valor): self
    {
        $digitos = self::somenteDigitos($valor);
        $tipo = self::tipoPeloTamanho($digitos);

        if (! self::digitosVerificadoresConferem($digitos, $tipo)) {
            throw new InvalidArgumentException("Documento federal inválido: {$valor}.");
        }

        return new self($digitos, $tipo);
    }

    public static function ehValido(string $valor): bool
    {
        try {
            self::deCpfOuCnpj($valor);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public static function somenteDigitos(string $valor): string
    {
        return preg_replace('/\D/', '', $valor) ?? '';
    }

    public function ehCnpj(): bool
    {
        return $this->tipo === TipoPessoa::Juridica;
    }

    public function formatado(): string
    {
        return self::mascarado($this->digitos);
    }

    /**
     * A mesma escrita de `formatado()`, para quem ainda nao tem uma instancia: o
     * formulario hidrata o campo com o que esta na coluna, que e so digito.
     *
     * Texto sem tamanho de CPF nem de CNPJ volta como veio. Hidratar um cadastro
     * nao e hora de recusar o que ja esta gravado; quem recusa e a validacao, na
     * gravacao seguinte.
     */
    public static function mascarado(string $valor): string
    {
        $digitos = self::somenteDigitos($valor);

        return match (strlen($digitos)) {
            11 => self::aplicarMascara('###.###.###-##', $digitos),
            14 => self::aplicarMascara('##.###.###/####-##', $digitos),
            default => $valor,
        };
    }

    public function __toString(): string
    {
        return $this->digitos;
    }

    private static function tipoPeloTamanho(string $digitos): TipoPessoa
    {
        return match (strlen($digitos)) {
            11 => TipoPessoa::Fisica,
            14 => TipoPessoa::Juridica,
            default => throw new InvalidArgumentException(
                'Documento federal precisa ter 11 dígitos (CPF) ou 14 (CNPJ).'
            ),
        };
    }

    private static function digitosVerificadoresConferem(string $digitos, TipoPessoa $tipo): bool
    {
        if (preg_match('/^(\d)\1+$/', $digitos) === 1) {
            return false;
        }

        return $tipo === TipoPessoa::Fisica
            ? self::cpfConfere($digitos)
            : self::cnpjConfere($digitos);
    }

    private static function cpfConfere(string $digitos): bool
    {
        $primeiro = self::digitoPorPesoDecrescente(substr($digitos, 0, 9), 10);
        $segundo = self::digitoPorPesoDecrescente(substr($digitos, 0, 9).$primeiro, 11);

        return substr($digitos, 9) === $primeiro.$segundo;
    }

    private static function cnpjConfere(string $digitos): bool
    {
        $primeiro = self::digitoPorPesosCiclicos(substr($digitos, 0, 12));
        $segundo = self::digitoPorPesosCiclicos(substr($digitos, 0, 12).$primeiro);

        return substr($digitos, 12) === $primeiro.$segundo;
    }

    private static function digitoPorPesoDecrescente(string $base, int $pesoInicial): string
    {
        $soma = 0;
        foreach (str_split($base) as $posicao => $algarismo) {
            $soma += ((int) $algarismo) * ($pesoInicial - $posicao);
        }

        return self::restoEmDigito($soma);
    }

    private static function digitoPorPesosCiclicos(string $base): string
    {
        $pesos = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $inicio = count($pesos) - strlen($base);

        $soma = 0;
        foreach (str_split($base) as $posicao => $algarismo) {
            $soma += ((int) $algarismo) * $pesos[$inicio + $posicao];
        }

        return self::restoEmDigito($soma);
    }

    private static function restoEmDigito(int $soma): string
    {
        $resto = $soma % 11;

        return (string) ($resto < 2 ? 0 : 11 - $resto);
    }

    private static function aplicarMascara(string $mascara, string $digitos): string
    {
        $resultado = '';
        $posicao = 0;

        foreach (str_split($mascara) as $caractere) {
            $resultado .= $caractere === '#' ? ($digitos[$posicao++] ?? '') : $caractere;
        }

        return $resultado;
    }
}
