<?php

declare(strict_types=1);

namespace App\Consultas;

use App\Domain\Enums\StatusNota;
use App\Fiscal\Respostas\NfseConsultada;
use App\Models\Nota;

/**
 * O que o provedor diz da nota contra o que este sistema guarda dela.
 *
 * Foi a divergencia que ja ficou escondida numa nota do GISS: cancelada no
 * provedor, autorizada aqui, porque a resposta do cancelamento foi lida errado.
 * A consulta nao corrige nada sozinha; ela diz em frase o que nao bate, e quem
 * opera decide.
 *
 * So compara quando o RPS consultado e o da propria nota. Consultar outro RPS a
 * partir desta tela e legitimo, e ai nao ha o que comparar.
 */
final readonly class ConferenciaComOProvedor
{
    /**
     * @return list<string>
     */
    public function divergencias(Nota $nota, NfseConsultada $consultada, string $numeroDoRps, string $serieDoRps): array
    {
        if (! $this->ehORpsDaNota($nota, $numeroDoRps, $serieDoRps)) {
            return [];
        }

        $situacao = $nota->status->getLabel();

        if (! $consultada->foiEncontrada()) {
            return $this->jaExisteNoProvedor($nota)
                ? [(string) __('Aqui a nota está :situacao, e o provedor não devolveu NFS-e para este RPS.', ['situacao' => $situacao])]
                : [];
        }

        return array_values(array_filter([
            $consultada->cancelada && $nota->status !== StatusNota::Cancelada
                ? (string) __('O provedor registrou o cancelamento em :data, e aqui a nota está :situacao.', ['data' => $consultada->canceladaEm, 'situacao' => $situacao])
                : null,
            ! $consultada->cancelada && $nota->status === StatusNota::Cancelada
                ? (string) __('Aqui a nota está cancelada, e o provedor não devolveu registro de cancelamento.')
                : null,
            blank($nota->numero_nfse)
                ? (string) __('O provedor tem a NFS-e :numero para este RPS, e aqui a nota está :situacao, sem número.', ['numero' => $consultada->numero, 'situacao' => $situacao])
                : null,
            filled($nota->numero_nfse) && $nota->numero_nfse !== $consultada->numero
                ? (string) __('Aqui a nota tem o número :local, e o provedor devolveu :provedor.', ['local' => $nota->numero_nfse, 'provedor' => $consultada->numero])
                : null,
        ]));
    }

    public function ehORpsDaNota(Nota $nota, string $numeroDoRps, string $serieDoRps): bool
    {
        return trim($numeroDoRps) === (string) $nota->numero && trim($serieDoRps) === $nota->serie;
    }

    private function jaExisteNoProvedor(Nota $nota): bool
    {
        return in_array($nota->status, [StatusNota::Autorizada, StatusNota::Cancelada, StatusNota::Substituida], true);
    }
}
