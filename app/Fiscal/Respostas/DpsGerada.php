<?php

declare(strict_types=1);

namespace App\Fiscal\Respostas;

/**
 * Retorno de POST /v1/nfse/xml. Ainda nao ha chave: na NFS-e a chave so existe
 * depois da autorizacao. O que se guarda agora e o `id_dps`, deterministico, que
 * e o unico caminho de recuperacao se a transmissao se perder.
 */
final readonly class DpsGerada
{
    public function __construct(
        public string $idDps,
        public string $xmlEmBase64,
        public string $layout,
        public string $provedor,
    ) {}

    /**
     * @param  array<string, mixed>  $corpo
     */
    public static function doCorpoDaResposta(array $corpo): self
    {
        $resposta = new LeitorDaResposta($corpo);

        return new self(
            idDps: $resposta->texto('id_dps'),
            xmlEmBase64: $resposta->texto('xml_b64'),
            layout: $resposta->texto('layout'),
            provedor: $resposta->texto('provedor'),
        );
    }

    public function xml(): string
    {
        return base64_decode($this->xmlEmBase64, true) ?: '';
    }
}
