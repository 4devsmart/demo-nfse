<?php

declare(strict_types=1);

namespace App\Actions\Notas;

use App\Domain\Enums\StatusNota;
use App\Domain\Notas\TributacaoRespondida;
use App\Models\Empresa;
use App\Models\Nota;
use Illuminate\Support\Str;

/**
 * Cria o rascunho da nota. Serie, numero, ambiente e referencia sao do emitente,
 * nao do formulario: e por isso que eles nascem aqui e nao na tela.
 */
final readonly class AbrirNota
{
    public function __construct(private ReservarNumeroDaDps $reservarNumero) {}

    /**
     * @param  array<string, mixed>  $dados
     */
    public function executar(array $dados): Nota
    {
        $dados = TributacaoRespondida::aplicadaEm($dados);

        $empresa = Empresa::query()->findOrFail($dados['empresa_id']);
        assert($empresa instanceof Empresa);

        return Nota::query()->create([
            ...$dados,
            'serie' => $empresa->serie_dps,
            'numero' => $this->reservarNumero->executar($empresa),
            'ambiente' => $empresa->ambiente,
            'status' => StatusNota::Rascunho,
            'referencia' => (string) Str::ulid(),
        ]);
    }
}
