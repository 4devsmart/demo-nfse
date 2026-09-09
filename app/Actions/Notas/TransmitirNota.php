<?php

declare(strict_types=1);

namespace App\Actions\Notas;

use App\Domain\Enums\StatusNota;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Excecoes\DesfechoIndeterminado;
use App\Fiscal\Respostas\NotaTransmitida;
use App\Fiscal\Traducao\ContextoDaEmpresa;
use App\Models\Nota;
use LogicException;

/**
 * Passo 2 de 2: assina e envia. Um 502 aqui NAO autoriza repetir, a nota fica
 * como indeterminada e o caminho passa a ser ConsultarDpsPendente.
 */
final readonly class TransmitirNota
{
    public function __construct(
        private GatewayFiscal $gateway,
        private ContextoDaEmpresa $contextoDaEmpresa,
    ) {}

    public function executar(Nota $nota): Nota
    {
        $this->exigirDpsGerada($nota);

        try {
            $resposta = $this->gateway->transmitirDps(
                $this->contextoDaEmpresa->montar($nota->empresa, $nota->ambiente),
                (string) $nota->xml_dps,
            );
        } catch (DesfechoIndeterminado $falha) {
            $this->marcarComoIndeterminada($nota, $falha);

            throw $falha;
        }

        return $this->guardarResultado($nota, $resposta);
    }

    private function exigirDpsGerada(Nota $nota): void
    {
        $impedimento = $nota->impedimentos()->paraTransmitir();

        if ($impedimento !== null) {
            throw new LogicException($impedimento);
        }
    }

    private function guardarResultado(Nota $nota, NotaTransmitida $resposta): Nota
    {
        $nota->forceFill([
            'status' => $resposta->foiAutorizada() ? StatusNota::Autorizada : StatusNota::Rejeitada,
            'numero_nfse' => $resposta->numero ?: null,
            'chave' => $resposta->chave ?: null,
            'codigo_verificacao' => $resposta->codigoDeVerificacao ?: null,
            'protocolo' => $resposta->protocolo ?: null,
            'xml_autorizado' => $resposta->xmlEmBase64 ?: null,
            'mensagens' => [...$resposta->erros->paraArray(), ...$resposta->alertas->paraArray()],
            'transmitida_em' => now(),
        ])->save();

        return $nota;
    }

    private function marcarComoIndeterminada(Nota $nota, DesfechoIndeterminado $falha): void
    {
        $nota->forceFill([
            'status' => StatusNota::Indeterminada,
            'mensagens' => [['codigo' => $falha->codigo, 'descricao' => $falha->getMessage()]],
            'transmitida_em' => now(),
        ])->save();
    }
}
