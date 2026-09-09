<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ClassificacaoTributariaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Um codigo da tabela oficial de classificacao tributaria do IBS e da CBS
 * (`cClassTrib`), publicada pela SVRS e referenciada pelo Portal Nacional da
 * NF-e. E o par CST + `cClassTrib` que a DPS declara no grupo `ibscbs`.
 *
 * A tabela e so de leitura: quem a preenche e a importacao, e nao a tela.
 *
 * @property int $id
 * @property string $codigo `cClassTrib`, seis dígitos com zero à esquerda
 * @property string $cst CST do IBS/CBS, três dígitos
 * @property string $nome_cst
 * @property string $descricao
 * @property string $percentual_reducao_ibs
 * @property string $percentual_reducao_cbs
 * @property bool $exige_tributo
 * @property bool $permite_credito_presumido
 * @property bool $tributacao_regular
 * @property Carbon|null $vigencia_inicio
 * @property Carbon|null $vigencia_fim
 * @property string|null $url_legislacao
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'codigo', 'cst', 'nome_cst', 'descricao',
    'percentual_reducao_ibs', 'percentual_reducao_cbs',
    'exige_tributo', 'permite_credito_presumido', 'tributacao_regular',
    'vigencia_inicio', 'vigencia_fim', 'url_legislacao',
])]
class ClassificacaoTributaria extends Model
{
    /** @use HasFactory<ClassificacaoTributariaFactory> */
    use HasFactory;

    protected $table = 'classificacoes_tributarias';

    protected function casts(): array
    {
        return [
            'percentual_reducao_ibs' => 'decimal:4',
            'percentual_reducao_cbs' => 'decimal:4',
            'exige_tributo' => 'boolean',
            'permite_credito_presumido' => 'boolean',
            'tributacao_regular' => 'boolean',
            'vigencia_inicio' => 'date',
            'vigencia_fim' => 'date',
        ];
    }

    /**
     * Codigo revogado continua na tabela: nota antiga o referencia, e apaga-lo
     * deixaria a nota sem explicacao. O que ele nao pode e aparecer para
     * escolher numa nota nova.
     *
     * @param  Builder<self>  $consulta
     * @return Builder<self>
     */
    public function scopeVigente(Builder $consulta): Builder
    {
        return $consulta->where(fn (Builder $sub): Builder => $sub
            ->whereNull('vigencia_fim')
            ->orWhere('vigencia_fim', '>=', now()->toDateString()));
    }

    public function rotulo(): string
    {
        return "{$this->codigo} · {$this->descricao}";
    }
}
