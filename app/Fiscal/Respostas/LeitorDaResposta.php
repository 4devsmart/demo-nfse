<?php

declare(strict_types=1);

namespace App\Fiscal\Respostas;

/**
 * Lê o corpo que a API fiscal devolveu sem nunca derrubar a leitura.
 *
 * Existe por causa de uma assimetria que custa caro. `transmitirDps`,
 * `cancelarNota` e `substituirNota` interpretam a resposta DEPOIS de o documento
 * já existir no provedor. Ali um `(string)` sobre um array vira warning, que o
 * Laravel promove a `ErrorException`, e a exceção sobe antes de a Action gravar
 * o desfecho, a nota fica "DPS gerada" com a NFS-e autorizada do outro lado, e
 * quem opera reenvia.
 *
 * O contrato da API diz que `numero` é string e que `erros` é lista de objetos.
 * Mas o preço de o contrato ser quebrado uma vez é um documento fiscal
 * duplicado, e o preço de ler com cuidado é esta classe. Campo que não dá para
 * entender vira vazio; a resposta chega inteira de qualquer jeito.
 *
 * É a ÚNICA leitura tolerante do projeto: quem recebe algo daqui já pode contar
 * com o tipo, e não precisa se defender de novo.
 */
final readonly class LeitorDaResposta
{
    /**
     * @param  array<string, mixed>  $corpo  o JSON da resposta, ja decodificado
     */
    public function __construct(private array $corpo) {}

    public function texto(string $campo, string $padrao = ''): string
    {
        $valor = $this->corpo[$campo] ?? null;

        if (! is_scalar($valor)) {
            return $padrao;
        }

        return trim((string) $valor);
    }

    public function inteiro(string $campo, int $padrao): int
    {
        $valor = $this->corpo[$campo] ?? null;

        return is_numeric($valor) ? (int) $valor : $padrao;
    }

    public function logico(string $campo, bool $padrao = false): bool
    {
        $valor = $this->corpo[$campo] ?? null;

        return is_scalar($valor) ? (bool) $valor : $padrao;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function lista(string $campo): array
    {
        $valor = $this->corpo[$campo] ?? null;

        return is_array($valor) ? $valor : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function objeto(string $campo): array
    {
        $valor = $this->corpo[$campo] ?? null;

        return is_array($valor) ? $valor : [];
    }

    /**
     * Um leitor do grupo aninhado. `/v1/ping` devolve `{"versao": {...}}`, e sem
     * isto cada nivel repetia o embrulho.
     */
    public function dentroDe(string $campo): self
    {
        return new self($this->objeto($campo));
    }
}
