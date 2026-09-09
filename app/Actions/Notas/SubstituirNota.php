<?php

declare(strict_types=1);

namespace App\Actions\Notas;

use App\Domain\Enums\StatusNota;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Excecoes\DesfechoIndeterminado;
use App\Fiscal\Pedidos\MotivoDoCancelamento;
use App\Fiscal\Pedidos\NotaSubstituida;
use App\Fiscal\Respostas\EventoRegistrado;
use App\Fiscal\Traducao\ContextoDaEmpresa;
use App\Fiscal\Traducao\MontadorDaDps;
use App\Models\Nota;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Troca uma NFS-e autorizada por outra: a substituta nasce como copia corrigida
 * da original, com numeracao propria, e o evento leva as duas.
 *
 * Se a chamada falhar, a substituta fica como rascunho em vez de sumir, ela
 * carrega o que o usuario acabou de corrigir, e o numero ja foi consumido de
 * qualquer forma. Dali da para emitir normalmente e cancelar a original a mao.
 *
 * Falhar e nao saber sao coisas diferentes. Sem resposta, a substituta nao volta
 * a rascunho: ela PODE existir no provedor, e emiti-la de novo duplicaria
 * documento fiscal. Fica Indeterminada, e o caminho e "Consultar por RPS":
 * serie e numero ja foram reservados aqui.
 */
final readonly class SubstituirNota
{
    public function __construct(
        private GatewayFiscal $gateway,
        private ContextoDaEmpresa $contextoDaEmpresa,
        private MontadorDaDps $montador,
        private ReservarNumeroDaDps $reservarNumero,
    ) {}

    /**
     * @param  array<string, mixed>  $correcoes
     */
    public function executar(Nota $original, array $correcoes, MotivoDoCancelamento $motivo): EventoRegistrado
    {
        $this->exigirNotaAutorizada($original);

        $substituta = $this->abrirSubstituta($original, $correcoes);

        try {
            $evento = $this->gateway->substituirNota(
                $this->contextoDaEmpresa->montar($original->empresa, $original->ambiente),
                $this->montador->montar($substituta),
                NotaSubstituida::identificadaPor(
                    (string) $original->numero_nfse,
                    $original->serie,
                    (string) $original->codigo_verificacao,
                ),
                $motivo,
            );
        } catch (DesfechoIndeterminado $falha) {
            $this->marcarComoIndeterminada($substituta, $falha);

            throw $falha;
        }

        $this->guardarResultado($original, $substituta, $evento, $motivo);

        return $evento;
    }

    private function marcarComoIndeterminada(Nota $substituta, DesfechoIndeterminado $falha): void
    {
        $substituta->forceFill([
            'status' => StatusNota::Indeterminada,
            'mensagens' => [['codigo' => $falha->codigo, 'descricao' => $falha->getMessage()]],
            'transmitida_em' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $correcoes  o que muda em relação à original
     */
    private function abrirSubstituta(Nota $original, array $correcoes): Nota
    {
        return DB::transaction(fn (): Nota => Nota::query()->create([
            // A substituta e a mesma operacao, corrigida: tudo o que descreve a
            // tributacao vem junto. Sem a suspensao e o beneficio, a nota nova
            // sai com base de calculo e ISSQN diferentes da original.
            ...$original->only([
                'empresa_id', 'cliente_id', 'cidade_prestacao_id', 'ambiente',
                'codigo_servico', 'cnae', 'item_lista_servico', 'nbs', 'competencia',
                'deducoes', 'desconto_incondicionado', 'desconto_condicionado',
                'tributacao_issqn', 'retencao_issqn',
                'tipo_suspensao', 'numero_processo_suspensao',
                'numero_beneficio_municipal', 'percentual_reducao_base',
                'total_tributos_federais', 'total_tributos_estaduais', 'total_tributos_municipais',
            ]),
            'descricao_servico' => $original->descricao_servico,
            'valor_servico' => $original->valor_servico,
            'aliquota_iss' => $original->aliquota_iss,
            ...$correcoes,
            'substitui_nota_id' => $original->getKey(),
            'serie' => $original->empresa->serie_dps,
            'numero' => $this->reservarNumero->executar($original->empresa),
            'status' => StatusNota::Rascunho,
            'referencia' => (string) Str::ulid(),
        ]));
    }

    private function guardarResultado(
        Nota $original,
        Nota $substituta,
        EventoRegistrado $evento,
        MotivoDoCancelamento $motivo,
    ): void {
        if (! $evento->foiConcluido()) {
            $substituta->forceFill(['mensagens' => $evento->mensagens->paraArray()])->save();

            return;
        }

        // O numero e o codigo de verificacao so vem quando o provedor os manda.
        // Sem eles a substituta ficaria autorizada sem identificacao propria, e
        // `paraSubstituir()` a recusaria para sempre: a correcao morreria num
        // passo so.
        $substituta->forceFill([
            'status' => StatusNota::Autorizada,
            'numero_nfse' => $evento->numero ?: null,
            'codigo_verificacao' => $evento->codigoDeVerificacao ?: null,
            'chave' => $evento->chave ?: null,
            'protocolo' => $evento->protocolo ?: null,
            'xml_autorizado' => $evento->documentoDoEvento(),
            'mensagens' => $evento->mensagens->paraArray(),
            'transmitida_em' => now(),
        ])->save();

        $original->forceFill([
            'status' => StatusNota::Substituida,
            'motivo_cancelamento' => $motivo->descricao,
            'cancelada_em' => now(),
        ])->save();
    }

    private function exigirNotaAutorizada(Nota $nota): void
    {
        $impedimento = $nota->impedimentos()->paraSubstituir();

        if ($impedimento !== null) {
            throw new LogicException($impedimento);
        }
    }
}
