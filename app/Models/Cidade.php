<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ValueObjects\CodigoIbge;
use Database\Factories\CidadeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * O município pelo código do IBGE. É ele que decide o provedor de NFS-e: sem
 * esta tabela não há como saber quem atende o emitente nem onde o serviço foi
 * prestado.
 *
 * @property int $id
 * @property string $codigo_ibge sete dígitos
 * @property string $nome
 * @property string $uf
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['codigo_ibge', 'nome', 'uf'])]
class Cidade extends Model
{
    /** @use HasFactory<CidadeFactory> */
    use HasFactory;

    protected $table = 'cidades';

    public function codigo(): CodigoIbge
    {
        return CodigoIbge::deSeteDigitos($this->codigo_ibge);
    }

    public function nomeComUf(): string
    {
        return "{$this->nome}/{$this->uf}";
    }
}
