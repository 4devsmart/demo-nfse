<?php

declare(strict_types=1);

namespace App\Consultas;

use App\Domain\Enums\StatusNota;
use App\Fiscal\Excecoes\CodigoDeFalha;

/**
 * O conteudo do guia do fluxo, montado a partir dos proprios tipos do sistema.
 * Nenhum texto e duplicado aqui: mudou o significado no enum, o guia muda
 * junto.
 */
final readonly class GuiaDeEmissao
{
    /**
     * @return list<array{situacao: StatusNota, significado: string, proximoPasso: string, chamada: ?string}>
     */
    public function estados(): array
    {
        return array_map(
            static fn (StatusNota $status): array => [
                'situacao' => $status,
                'significado' => $status->significado(),
                'proximoPasso' => $status->proximoPasso(),
                'chamada' => $status->chamadaQueAvanca(),
            ],
            StatusNota::cases(),
        );
    }

    /**
     * @return list<array{codigo: CodigoDeFalha, significado: string, oQueFazer: string, podeRepetir: bool}>
     */
    public function falhas(): array
    {
        return array_map(
            static fn (CodigoDeFalha $codigo): array => [
                'codigo' => $codigo,
                'significado' => $codigo->significado(),
                'oQueFazer' => $codigo->oQueFazer(),
                'podeRepetir' => $codigo->podeRepetir(),
            ],
            CodigoDeFalha::cases(),
        );
    }

    /**
     * @return list<array{titulo: string, chamada: string, explicacao: string}>
     */
    public function passos(): array
    {
        return [
            [
                'titulo' => __('Gerar a DPS'),
                'chamada' => 'POST /v1/nfse/xml',
                'explicacao' => __('Monta o XML da DPS e devolve o id_dps. Não assina, não pede certificado e não envia nada ao provedor. Serve para conferir o documento antes de transmitir.'),
            ],
            [
                'titulo' => __('Transmitir'),
                'chamada' => 'POST /v1/nfse/transmissao',
                'explicacao' => __('Recebe o XML de volta junto com o certificado A1, assina e envia ao provedor do município.'),
            ],
            [
                'titulo' => __('Emitir'),
                'chamada' => __('as duas, em sequência'),
                'explicacao' => __('Executa a geração e a transmissão em uma chamada só, para quando não há motivo para conferir o XML antes.'),
            ],
        ];
    }
}
