<?php

declare(strict_types=1);

namespace App\Actions\Notas;

use App\Domain\Enums\StatusNota;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Excecoes\DesfechoIndeterminado;
use App\Fiscal\Pedidos\MotivoDoCancelamento;
use App\Fiscal\Pedidos\NotaCancelada;
use App\Fiscal\Respostas\EventoRegistrado;
use App\Fiscal\Traducao\ContextoDaEmpresa;
use App\Models\Nota;
use LogicException;

/**
 * Chamada unica: a biblioteca nao expoe o XML do evento antes de envia-lo. Se o
 * cancelamento se perder, a recuperacao e consultar a nota, a chave ja se tem.
 *
 * A nota fica Autorizada no desfecho indeterminado, e nao Cancelada: e o que se
 * sabe. O que se registra e a duvida, para que quem abrir a nota depois veja
 * que ha um evento sem resposta antes de mandar outro.
 */
final readonly class CancelarNota
{
    public function __construct(
        private GatewayFiscal $gateway,
        private ContextoDaEmpresa $contextoDaEmpresa,
    ) {}

    public function executar(Nota $nota, MotivoDoCancelamento $motivo): EventoRegistrado
    {
        $this->exigirNotaAutorizada($nota);

        try {
            $evento = $this->gateway->cancelarNota(
                $this->contextoDaEmpresa->montar($nota->empresa, $nota->ambiente),
                $this->identificacao($nota),
                $motivo,
            );
        } catch (DesfechoIndeterminado $falha) {
            $this->registrarDuvida($nota, $falha);

            throw $falha;
        }

        $this->guardarResultado($nota, $evento, $motivo);

        return $evento;
    }

    /**
     * Padrao Nacional pela chave, ABRASF pelo numero. `ImpedimentosDaNota` ja
     * garantiu que o dado de cada caminho existe.
     */
    private function identificacao(Nota $nota): NotaCancelada
    {
        if ($nota->identificadaPeloNumero()) {
            return NotaCancelada::peloNumero(
                (string) $nota->numero_nfse,
                (string) $nota->codigo_verificacao,
                (string) $nota->chave,
            );
        }

        return NotaCancelada::pelaChave((string) $nota->chave);
    }

    /**
     * O cancelamento PODE ter sido registrado. Reenviar sem conferir arrisca um
     * segundo evento sobre a mesma nota, entao o caminho e "Consultar no
     * provedor", a chave ja se tem.
     */
    private function registrarDuvida(Nota $nota, DesfechoIndeterminado $falha): void
    {
        $nota->forceFill([
            'mensagens' => [['codigo' => $falha->codigo, 'descricao' => $falha->getMessage()]],
        ])->save();
    }

    private function exigirNotaAutorizada(Nota $nota): void
    {
        $impedimento = $nota->impedimentos()->paraCancelar();

        if ($impedimento !== null) {
            throw new LogicException($impedimento);
        }
    }

    private function guardarResultado(Nota $nota, EventoRegistrado $evento, MotivoDoCancelamento $motivo): void
    {
        if (! $evento->foiConcluido()) {
            $nota->forceFill(['mensagens' => $evento->mensagens->paraArray()])->save();

            return;
        }

        $nota->forceFill([
            'status' => StatusNota::Cancelada,
            'cancelada_em' => now(),
            'motivo_cancelamento' => $motivo->descricao,
            'mensagens' => $evento->mensagens->paraArray(),

            // O documento do evento e o protocolo dele vem nesta resposta e nao
            // vem de novo: a consulta pela chave devolve a NFS-e como foi
            // autorizada, sem o evento. Descarta-los deixava a nota Cancelada
            // sem nada que provasse o cancelamento. Quando o provedor nao manda
            // o documento, `documentoDoEvento()` devolve nulo e a coluna fica
            // vazia: melhor nada do que um `.xml` com uma frase dentro.
            'xml_evento' => $evento->documentoDoEvento(),
            'protocolo' => $evento->protocolo ?: $nota->protocolo,
        ])->save();
    }
}
