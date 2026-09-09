<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\TipoPessoa;
use App\Domain\ValueObjects\CodigoIbge;
use App\Domain\ValueObjects\DocumentoFederal;
use App\Fiscal\Dps\Endereco;
use App\Fiscal\Dps\Pessoa;
use Database\Factories\ClienteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * O tomador do serviço, quem recebe a nota. Vai no grupo `toma` da DPS.
 *
 * @property int $id
 * @property TipoPessoa $tipo_pessoa
 * @property string $cpf_cnpj só dígitos; a máscara é enfeite de digitação
 * @property string $razao_social
 * @property string|null $inscricao_municipal
 * @property int $cidade_id
 * @property string $cep
 * @property string $logradouro
 * @property string $numero
 * @property string|null $complemento
 * @property string $bairro
 * @property string|null $telefone
 * @property string|null $email
 * @property-read Cidade $cidade
 * @property-read Collection<int, Nota> $notas
 */
#[Fillable([
    'tipo_pessoa', 'cpf_cnpj', 'razao_social', 'inscricao_municipal',
    'cidade_id', 'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'telefone', 'email',
])]
class Cliente extends Model
{
    /** @use HasFactory<ClienteFactory> */
    use HasFactory;

    protected $table = 'clientes';

    protected function casts(): array
    {
        return [
            'tipo_pessoa' => TipoPessoa::class,
        ];
    }

    public function cidade(): BelongsTo
    {
        return $this->belongsTo(Cidade::class);
    }

    public function notas(): HasMany
    {
        return $this->hasMany(Nota::class);
    }

    public function documentoFederal(): DocumentoFederal
    {
        return DocumentoFederal::deCpfOuCnpj($this->cpf_cnpj);
    }

    /**
     * O que impede apagar este tomador, ou `null` quando nada impede. Pelo mesmo
     * motivo do emitente: a nota que o referencia guarda documento fiscal sem
     * segunda via, e a FK recusa a exclusão.
     */
    public function impedimentoParaApagar(): ?string
    {
        return $this->notas()->exists()
            ? 'Este tomador já recebeu nota. Apagá-lo levaria junto o XML autorizado e o protocolo, que não têm segunda via.'
            : null;
    }

    public function municipio(): CodigoIbge
    {
        return $this->cidade->codigo();
    }

    /**
     * Pessoa fisica nao tem inscricao municipal. Se sobrou uma na coluna, de um
     * cadastro que era PJ antes, ela nao vai para o `toma`: IM em documento de
     * CPF e rejeicao na prefeitura.
     */
    public function comoTomador(): Pessoa
    {
        $tomador = Pessoa::identificadaPor($this->documentoFederal(), $this->razao_social);

        if ($this->tipo_pessoa !== TipoPessoa::Fisica) {
            $tomador = $tomador->comInscricaoMunicipal((string) $this->inscricao_municipal);
        }

        return $tomador
            ->comContato((string) $this->email, (string) $this->telefone)
            ->em($this->endereco());
    }

    public function endereco(): Endereco
    {
        return new Endereco(
            municipio: $this->municipio(),
            uf: $this->cidade->uf,
            cep: $this->cep,
            logradouro: $this->logradouro,
            numero: $this->numero,
            bairro: $this->bairro,
            complemento: (string) $this->complemento,
        );
    }
}
