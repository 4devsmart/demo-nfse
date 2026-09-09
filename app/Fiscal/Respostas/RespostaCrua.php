<?php

declare(strict_types=1);

namespace App\Fiscal\Respostas;

/**
 * Consultas devolvem o retorno da biblioteca sem reinterpretacao: o formato
 * varia por provedor, e normalizar esconderia informacao que so o provedor tem.
 */
final readonly class RespostaCrua
{
    public function __construct(
        public int $codigo,
        public string $resposta,
        public string $xmlEmBase64,
    ) {}

    /**
     * @param  array<string, mixed>  $corpo
     */
    public static function doCorpoDaResposta(array $corpo): self
    {
        $lida = new LeitorDaResposta($corpo);

        return new self(
            codigo: $lida->inteiro('codigo', -1),
            resposta: $lida->texto('resposta'),
            xmlEmBase64: $lida->texto('xml_b64'),
        );
    }

    public function foiSucesso(): bool
    {
        return $this->codigo === 0;
    }
}
