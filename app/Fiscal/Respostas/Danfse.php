<?php

declare(strict_types=1);

namespace App\Fiscal\Respostas;

final readonly class Danfse
{
    public function __construct(public string $pdfEmBase64) {}

    /**
     * @param  array<string, mixed>  $corpo
     */
    public static function doCorpoDaResposta(array $corpo): self
    {
        return new self(new LeitorDaResposta($corpo)->texto('pdf_b64'));
    }

    public function conteudo(): string
    {
        return base64_decode($this->pdfEmBase64, true) ?: '';
    }
}
