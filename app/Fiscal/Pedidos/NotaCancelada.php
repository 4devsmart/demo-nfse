<?php

declare(strict_types=1);

namespace App\Fiscal\Pedidos;

use InvalidArgumentException;

/**
 * Identifica a NFS-e no pedido de cancelamento. Sao dois jeitos, e quem escolhe
 * e o leiaute do provedor que autorizou a nota.
 *
 * O Padrao Nacional cancela por evento, e o evento aponta a chave de acesso. O
 * ABRASF nao tem chave: o webservice de cancelamento localiza a nota pelo
 * numero que o provedor atribuiu. O que a API devolve como `chave` numa nota
 * ABRASF e o link dela, quando o provedor manda um, e vai junto so por isso.
 *
 * O numero nao vai no pedido do Padrao Nacional: o corpo continua o mesmo de
 * antes, campo a campo, e uma versao da API que nao conheca `evento.numero` nao
 * tem como recusa-lo.
 */
final readonly class NotaCancelada
{
    private function __construct(
        public string $chave,
        public string $numero,
        public string $codigoDeVerificacao,
    ) {}

    public static function pelaChave(string $chave): self
    {
        if (trim($chave) === '') {
            throw new InvalidArgumentException(__('O cancelamento no Padrão Nacional precisa da chave de acesso.'));
        }

        return new self(trim($chave), '', '');
    }

    public static function peloNumero(string $numero, string $codigoDeVerificacao = '', string $chave = ''): self
    {
        if (trim($numero) === '') {
            throw new InvalidArgumentException(__('O cancelamento neste provedor precisa do número da NFS-e.'));
        }

        return new self(trim($chave), trim($numero), trim($codigoDeVerificacao));
    }

    /**
     * O que vai no topo do pedido.
     *
     * @return array<string, string>
     */
    public function paraApi(): array
    {
        return array_filter(['chave' => $this->chave], static fn (string $valor): bool => $valor !== '');
    }

    /**
     * O que vai dentro de `evento`, ao lado do motivo.
     *
     * @return array<string, string>
     */
    public function paraEvento(): array
    {
        return array_filter([
            'numero' => $this->numero,
            'codigo_verificacao' => $this->codigoDeVerificacao,
        ], static fn (string $valor): bool => $valor !== '');
    }
}
