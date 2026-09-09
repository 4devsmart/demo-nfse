<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IndicadorDeOperacaoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Um codigo do `cIndOp`, o indicador da operacao da DPS. Vem do Anexo VII da
 * NT 007, que o baseia no art. 11 da LC 214/2025: e ele que diz onde a operacao
 * se considera ocorrida, e portanto a quem cabe o IBS.
 *
 * Tabela de leitura: quem a preenche e a importacao.
 *
 * @property int $id
 * @property string $codigo seis dígitos com zero à esquerda
 * @property string $tipo_operacao
 * @property string $caracteristica
 * @property string $local_do_fornecimento
 * @property string $dispositivo_legal
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['codigo', 'tipo_operacao', 'caracteristica', 'local_do_fornecimento', 'dispositivo_legal'])]
class IndicadorDeOperacao extends Model
{
    /** @use HasFactory<IndicadorDeOperacaoFactory> */
    use HasFactory;

    protected $table = 'indicadores_de_operacao';

    public function rotulo(): string
    {
        return "{$this->codigo} · {$this->tipo_operacao}";
    }
}
