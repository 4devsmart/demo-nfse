<?php

declare(strict_types=1);

namespace App\Fiscal\Respostas;

/**
 * Um documento da fila do ADN. Pode ser a NFS-e ou um evento sobre ela, e os
 * dois vem com a mesma chave: e o `tipo` que separa.
 */
final readonly class DocumentoDistribuido
{
    /** Como o ADN nomeia o evento na fila. */
    private const TIPO_EVENTO = 'EVENTO';

    public function __construct(
        public int $nsu,
        public string $chave,
        public string $tipo,
        public string $xml,
    ) {}

    public function ehEventoDa(string $chave): bool
    {
        return $this->tipo === self::TIPO_EVENTO && $this->chave === $chave;
    }
}
