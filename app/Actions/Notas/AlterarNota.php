<?php

declare(strict_types=1);

namespace App\Actions\Notas;

use App\Domain\Enums\StatusNota;
use App\Domain\Notas\TributacaoRespondida;
use App\Models\Nota;
use LogicException;

/**
 * Mudar os dados invalida a DPS ja montada: o XML guardado descreve a nota
 * anterior, e transmiti-lo enviaria o que o usuario acabou de corrigir. Por isso
 * a nota volta a rascunho e o identificador antigo e descartado, ele nunca
 * chegou a virar nota, entao nao ha o que recuperar.
 */
final readonly class AlterarNota
{
    /**
     * @param  array<string, mixed>  $dados
     */
    public function executar(Nota $nota, array $dados): Nota
    {
        $this->exigirNotaEditavel($nota);

        $nota->fill(TributacaoRespondida::aplicadaEm($dados));

        if ($nota->status !== StatusNota::Rascunho) {
            $nota->forceFill([
                'status' => StatusNota::Rascunho,
                'id_dps' => null,
                'xml_dps' => null,
                'provedor' => null,
                'mensagens' => null,
            ]);
        }

        $nota->save();

        return $nota;
    }

    private function exigirNotaEditavel(Nota $nota): void
    {
        $impedimento = $nota->impedimentos()->paraAlterar();

        if ($impedimento !== null) {
            throw new LogicException($impedimento);
        }
    }
}
