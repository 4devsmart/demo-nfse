<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use InvalidArgumentException;
use Stringable;

/**
 * Valor monetario em centavos, para nao arrastar erro de ponto flutuante ate o
 * XML. A API espera numero JSON com ponto decimal (`1500.5`); e `emReais()` que
 * produz isso.
 */
final readonly class Dinheiro implements Stringable
{
    private function __construct(public int $centavos) {}

    public static function deReais(float|int|string $reais): self
    {
        if (! is_numeric($reais)) {
            throw new InvalidArgumentException("Valor monetário inválido: {$reais}.");
        }

        return new self((int) round(((float) $reais) * 100));
    }

    /**
     * Aceita o que o formulario devolve depois da mascara ("1.500,50") e
     * tambem o numero cru que vem do banco ("1500.50").
     */
    public static function deTexto(string|float|int|null $valor): self
    {
        if ($valor === null) {
            return self::zero();
        }

        if (is_float($valor) || is_int($valor)) {
            return self::deReais($valor);
        }

        return self::deReais(self::numeroDoTexto($valor));
    }

    /**
     * A virgula e o sinal de que os pontos sao separador de milhar. Sem ela,
     * ponto e decimal, com uma excecao: pontos que separam grupos de exatamente
     * tres digitos ("1.500", "1.500.000") so podem ser milhar, e essa e a
     * escrita que a mascara produz quando o valor nao tem centavos. Lida como
     * decimal, "1.500" virava R$ 1,50 numa nota de mil e quinhentos reais.
     *
     * O numero cru do banco nunca cai nessa forma: o cast `decimal:2` devolve
     * "1500.00" e o `decimal:4`, "2.7500", nenhum com grupo de tres.
     */
    public static function numeroDoTexto(string $texto): float
    {
        $limpo = trim($texto);

        if (str_contains($limpo, ',')) {
            $limpo = str_replace(',', '.', str_replace('.', '', $limpo));
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $limpo) === 1) {
            $limpo = str_replace('.', '', $limpo);
        }

        return is_numeric($limpo) ? (float) $limpo : 0.0;
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function somar(self $outro): self
    {
        return new self($this->centavos + $outro->centavos);
    }

    public function subtrair(self $outro): self
    {
        return new self($this->centavos - $outro->centavos);
    }

    public function multiplicarPor(Aliquota $aliquota): self
    {
        return new self((int) round($this->centavos * $aliquota->emFracao()));
    }

    /**
     * Arredondamento bancario (half-even): meio centavo sobe ou desce conforme
     * o centavo anterior, em vez de subir sempre.
     *
     * Existe por exigencia da NT 007 (7/2/2026), que fixou esse metodo para
     * `vPis` e `vCofins` e admitiu tolerancia de R$ 0,01 na conferencia. Fora
     * desses dois campos vale `multiplicarPor()`: trocar o arredondamento do
     * ISSQN por causa do PIS mudaria imposto municipal sem que a NT pedisse.
     */
    public function multiplicarComArredondamentoBancario(Aliquota $aliquota): self
    {
        return new self((int) round($this->centavos * $aliquota->emFracao(), 0, PHP_ROUND_HALF_EVEN));
    }

    public function ehZero(): bool
    {
        return $this->centavos === 0;
    }

    public function emReais(): float
    {
        return round($this->centavos / 100, 2);
    }

    public function formatado(): string
    {
        return 'R$ '.number_format($this->emReais(), 2, ',', '.');
    }

    public function __toString(): string
    {
        return number_format($this->emReais(), 2, '.', '');
    }
}
