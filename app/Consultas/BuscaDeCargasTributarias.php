<?php

declare(strict_types=1);

namespace App\Consultas;

use App\Models\CargaTributariaAproximada;

/**
 * A TabelaIBPTax como a tela precisa dela: dado o item da LC 116 e a UF do
 * emitente, quanto de tributo esta embutido no preco.
 *
 * A UF e do emitente, e nao do municipio da prestacao: o IBPT publica um
 * arquivo por UF e quem o baixa e a empresa, para os documentos que ela emite.
 */
final readonly class BuscaDeCargasTributarias
{
    /**
     * O codigo chega como o formulario o entrega. Desde que ele virou seletor, o
     * valor pode vir inteiro: chave numerica de array PHP nao e texto, e
     * "140101" atravessa como 140101. Recusar o que nao e string faria a carga
     * sumir em silencio para todo item da lista a partir do 10.01, que e a
     * maior parte dela.
     */
    public function paraServico(mixed $codigoDoServico, ?string $uf): ?CargaTributariaAproximada
    {
        if (! is_string($codigoDoServico) && ! is_int($codigoDoServico)) {
            return null;
        }

        $codigo = CargaTributariaAproximada::normalizarCodigo((string) $codigoDoServico);

        if ($codigo === '' || blank($uf)) {
            return null;
        }

        $carga = CargaTributariaAproximada::query()
            ->where('codigo', $codigo)
            ->where('uf', $uf)
            ->first();

        return $carga instanceof CargaTributariaAproximada ? $carga : null;
    }

    public function total(): int
    {
        return CargaTributariaAproximada::query()->count();
    }

    /**
     * A versao e a vigencia da tabela carregada, para a tela poder dizer de
     * quando e o numero que ela acabou de preencher.
     *
     * @return array{versao: string, vigencia_fim: string|null}|null
     */
    public function vigencia(): ?array
    {
        $carga = CargaTributariaAproximada::query()->orderByDesc('vigencia_fim')->first();

        if (! $carga instanceof CargaTributariaAproximada) {
            return null;
        }

        return [
            'versao' => $carga->versao,
            'vigencia_fim' => $carga->vigencia_fim?->format('d/m/Y'),
        ];
    }
}
