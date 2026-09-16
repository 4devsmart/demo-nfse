<?php

declare(strict_types=1);

namespace App\Actions\Notas;

use App\Domain\Enums\StatusNota;
use App\Fiscal\Respostas\NotaTransmitida;
use App\Models\Nota;

/**
 * Grava na nota o que o provedor respondeu. A transmissao e a consulta do lote
 * respondem no mesmo formato, e as duas precisam tirar dele a mesma conclusao:
 * uma nota nao pode ficar autorizada por um caminho e em processamento pelo
 * outro com a mesma resposta.
 */
final readonly class RegistrarDesfechoDaTransmissao
{
    /**
     * @param  bool  $acabouDeTransmitir  so a transmissao muda `transmitida_em`; a consulta do lote conclui o envio que ja aconteceu
     */
    public function executar(Nota $nota, NotaTransmitida $resposta, bool $acabouDeTransmitir): Nota
    {
        $emProcessamento = $resposta->estaEmProcessamento();

        $nota->forceFill([
            'status' => match (true) {
                $resposta->foiAutorizada() => StatusNota::Autorizada,
                $emProcessamento => StatusNota::EmProcessamento,
                default => StatusNota::Rejeitada,
            },
            'numero_nfse' => $this->daResposta($resposta->numero, $nota->numero_nfse, $acabouDeTransmitir),
            'chave' => $this->daResposta($resposta->chave, $nota->chave, $acabouDeTransmitir),
            'codigo_verificacao' => $this->daResposta($resposta->codigoDeVerificacao, $nota->codigo_verificacao, $acabouDeTransmitir),
            'protocolo' => $this->daResposta($resposta->protocolo, $nota->protocolo, $acabouDeTransmitir),

            // Lote sem desfecho nao tem documento autorizado. O XML que a API
            // manda junto, quando manda, e o do RPS enviado: gravado aqui, ele
            // liberaria o DANFSE de uma nota que talvez nunca exista.
            'xml_autorizado' => $emProcessamento ? null : ($resposta->xmlEmBase64 ?: null),

            'mensagens' => [...$resposta->erros->paraArray(), ...$resposta->alertas->paraArray()],
            ...($acabouDeTransmitir ? ['transmitida_em' => now()] : []),
        ])->save();

        return $nota;
    }

    /**
     * A transmissao abre um envio novo, e o que a resposta dela nao traz nao
     * vale mais: protocolo de um lote anterior apontaria para outro RPS. A
     * consulta do lote completa o envio que ja existe, e campo ausente nela nao
     * apaga o que a transmissao gravou. Sem o protocolo, a nota em
     * processamento nao teria mais como ser consultada nem reenviada.
     */
    private function daResposta(string $respondido, ?string $gravado, bool $acabouDeTransmitir): ?string
    {
        return match (true) {
            $respondido !== '' => $respondido,
            $acabouDeTransmitir => null,
            default => $gravado,
        };
    }
}
