<?php

declare(strict_types=1);

namespace App\Fiscal\Respostas;

/**
 * Retorno de POST /v1/nfse/transmissao e de POST /v1/nfse/transmissao/lote.
 * Rejeicao do provedor nao e erro de protocolo: volta 422 com este mesmo corpo,
 * e `status` diz o desfecho. Lote ainda sem decisao volta 202.
 */
final readonly class NotaTransmitida
{
    public function __construct(
        public string $status,
        public string $numero,
        public string $chave,
        public string $codigoDeVerificacao,
        public string $protocolo,
        public string $situacao,
        public string $xmlEmBase64,
        public Mensagens $erros,
        public Mensagens $alertas,
    ) {}

    /**
     * @param  array<string, mixed>  $corpo
     */
    public static function doCorpoDaResposta(array $corpo): self
    {
        $resposta = new LeitorDaResposta($corpo);

        return new self(
            status: $resposta->texto('status', 'erro'),
            numero: $resposta->texto('numero'),
            chave: $resposta->texto('chave'),
            codigoDeVerificacao: $resposta->texto('codigo_verificacao'),
            protocolo: $resposta->texto('protocolo'),
            situacao: $resposta->texto('situacao'),
            xmlEmBase64: $resposta->texto('xml_b64'),
            erros: Mensagens::daLista($resposta->lista('erros')),
            alertas: Mensagens::daLista($resposta->lista('alertas')),
        );
    }

    public function foiAutorizada(): bool
    {
        return $this->status === 'autorizado';
    }

    /**
     * O provedor recebeu o lote e ainda nao decidiu. Nao ha nota nem recusa, so
     * o protocolo, que e por onde se pergunta o desfecho.
     */
    public function estaEmProcessamento(): bool
    {
        return $this->status === 'processando';
    }
}
