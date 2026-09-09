<?php

declare(strict_types=1);

namespace App\Domain\Notas;

use App\Models\Nota;

/**
 * O que impede cada operação, em uma frase, ou `null` quando nada impede.
 *
 * A mesma regra precisa ser dita para dois públicos:
 *
 *   - a tela, que desabilita o botão e mostra o motivo antes do clique;
 *   - o caso de uso, que recusa a operação mesmo quando chamada de um job,
 *     de um comando ou de um teste, onde não há botão nenhum.
 *
 * Cada resposta é um `match (true)`: a condição e a frase que ela produz ficam
 * lado a lado, uma por linha, e a ordem em que se lê é a ordem em que se
 * decide.
 *
 * O `(string)` sobre o `__()` tem motivo. O Larastan tipa a tradução como uma
 * união benevolente de `array|string`, que o analisador tolera onde se espera
 * texto. A benevolência sobrevive a um `return __(...)` direto, mas some quando
 * o valor é unido a `null`, que é o que todo braço de `null` aqui faz: o tipo
 * vira `array|string|null` cru e a assinatura `?string` deixa de fechar. Trocar
 * o ternário por `match` não muda isso, foi medido. O cast diz o que já é
 * verdade: a chave é sempre uma frase inteira, nunca uma lista.
 */
final readonly class ImpedimentosDaNota
{
    public function __construct(private Nota $nota) {}

    /**
     * Gerar a DPS não assina, não fala com o provedor e não pede certificado,
     * então o que impede aqui é só o estado. Uma nota que já saiu com sucesso
     * não volta a ser montada: remontar zeraria o id_dps e o XML de um
     * documento que existe no provedor.
     */
    public function paraGerarDps(): ?string
    {
        return match (true) {
            $this->nota->status->permiteTransmitir() => null,
            default => (string) __('Uma nota :situacao não é montada de novo.', ['situacao' => $this->nota->status->getLabel()]),
        };
    }

    /**
     * Emitir faz gerar e transmitir em sequência, então o que impede é o mesmo
     * que impede transmitir, menos a exigência de já haver DPS montada.
     */
    public function paraEmitir(): ?string
    {
        return match (true) {
            ! $this->nota->status->permiteTransmitir() => (string) __('Uma nota :situacao não é transmitida de novo.', ['situacao' => $this->nota->status->getLabel()]),
            default => $this->paraFalarComOProvedor(),
        };
    }

    public function paraTransmitir(): ?string
    {
        return match (true) {
            $this->paraEmitir() !== null => $this->paraEmitir(),
            $this->nota->temDpsMontada() => null,
            default => (string) __('Gere a DPS primeiro: é ela que vai ao provedor.'),
        };
    }

    /**
     * Toda chamada ao provedor é assinada. Vale para consulta da DPS, consulta
     * pela chave, consulta por RPS, cancelamento e substituição.
     */
    public function paraFalarComOProvedor(): ?string
    {
        return match (true) {
            $this->nota->empresa->temCertificado() => null,
            default => (string) __('O emitente ainda não tem certificado A1. Sem ele dá para gerar a DPS, mas não para falar com o provedor.'),
        };
    }

    public function paraConsultarADps(): ?string
    {
        return match (true) {
            $this->paraFalarComOProvedor() !== null => $this->paraFalarComOProvedor(),
            filled($this->nota->id_dps) => null,
            default => (string) __('A consulta precisa do id_dps, que nasce ao gerar a DPS.'),
        };
    }

    public function paraConsultarPelaChave(): ?string
    {
        return match (true) {
            $this->paraFalarComOProvedor() !== null => $this->paraFalarComOProvedor(),
            filled($this->nota->chave) => null,
            default => (string) __('A chave de acesso só existe depois que o provedor autoriza a nota.'),
        };
    }

    public function paraCancelar(): ?string
    {
        return match (true) {
            ! $this->nota->status->permiteCancelar() => (string) __('Uma nota :situacao não é cancelada.', ['situacao' => $this->nota->status->getLabel()]),
            $this->paraFalarComOProvedor() !== null => $this->paraFalarComOProvedor(),
            filled($this->nota->chave) => null,
            default => (string) __('O cancelamento vai pela chave de acesso, que esta nota ainda não tem.'),
        };
    }

    public function paraSubstituir(): ?string
    {
        return match (true) {
            $this->paraCancelar() !== null => $this->paraCancelar(),
            filled($this->nota->numero_nfse) => null,
            default => (string) __('A substituição identifica a nota antiga pelo número que o provedor atribuiu.'),
        };
    }

    public function paraAlterar(): ?string
    {
        return match (true) {
            $this->nota->status->permiteEditar() => null,
            default => (string) __('Uma nota :situacao não pode ser alterada: o que existe é cancelar e emitir outra.', ['situacao' => $this->nota->status->getLabel()]),
        };
    }

    /**
     * O documento do evento vem da fila DF-e do emitente, e a fila e indexada
     * pela chave: sem ela nao ha o que procurar. Nota que ja tem o documento
     * tambem nao tem: ele nao muda.
     */
    public function paraBuscarOEvento(): ?string
    {
        return match (true) {
            $this->paraFalarComOProvedor() !== null => $this->paraFalarComOProvedor(),
            ! $this->nota->status->teveEvento() => (string) __('Só há evento para buscar depois que a nota é cancelada ou substituída.'),
            blank($this->nota->chave) => (string) __('A busca é pela chave de acesso, que esta nota não tem.'),
            $this->nota->temXmlDoEvento() => (string) __('O documento do evento já está guardado nesta nota.'),
            default => null,
        };
    }

    public function paraImprimir(): ?string
    {
        return match (true) {
            $this->nota->temXmlAutorizado() => null,
            default => (string) __('O DANFSE é desenhado a partir do XML autorizado: ele só existe depois que o provedor autoriza a nota.'),
        };
    }
}
