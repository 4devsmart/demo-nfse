<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ItemDaNbsFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Um item da Nomenclatura Brasileira de Servicos correlacionado a um subitem da
 * LC 116. E o `cNBS` da DPS, exigido pela rejeicao E0322 sempre que a nota
 * declara IBS/CBS.
 *
 * Tabela de leitura: quem a preenche e a importacao.
 *
 * @property int $id
 * @property string $item_lista_servico quatro dígitos sem ponto
 * @property string $nbs doze caracteres pontuados ("1.1502.10.00")
 * @property string $descricao
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['item_lista_servico', 'nbs', 'descricao'])]
class ItemDaNbs extends Model
{
    /** @use HasFactory<ItemDaNbsFactory> */
    use HasFactory;

    protected $table = 'itens_da_nbs';
}
