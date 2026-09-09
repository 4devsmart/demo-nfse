<?php

declare(strict_types=1);

namespace App\Fiscal\Dps;

use App\Domain\ValueObjects\DocumentoFederal;

/**
 * Prestador ou tomador da DPS (`infDPS.prest` / `infDPS.toma`). Construida de
 * forma fluida: o obrigatorio vai no construtor nomeado, o resto se acrescenta.
 */
final readonly class Pessoa
{
    private function __construct(
        public DocumentoFederal $documento,
        public string $nome,
        public ?Endereco $endereco,
        public string $inscricaoMunicipal,
        public string $email,
        public string $telefone,
        public ?RegimeTributario $regimeTributario,
    ) {}

    public static function identificadaPor(DocumentoFederal $documento, string $nome): self
    {
        return new self($documento, $nome, null, '', '', '', null);
    }

    public function em(Endereco $endereco): self
    {
        return $this->com(endereco: $endereco);
    }

    public function comInscricaoMunicipal(string $inscricao): self
    {
        return $this->com(inscricaoMunicipal: $inscricao);
    }

    public function comContato(string $email, string $telefone): self
    {
        return $this->com(email: $email, telefone: $telefone);
    }

    public function sobRegime(RegimeTributario $regime): self
    {
        return $this->com(regimeTributario: $regime);
    }

    /**
     * Um só lugar reconstrói a pessoa, para que cada `com*` não repita os sete
     * campos na ordem certa e um campo novo não obrigue a mexer em todos.
     */
    private function com(
        ?Endereco $endereco = null,
        ?string $inscricaoMunicipal = null,
        ?string $email = null,
        ?string $telefone = null,
        ?RegimeTributario $regimeTributario = null,
    ): self {
        return new self(
            $this->documento,
            $this->nome,
            $endereco ?? $this->endereco,
            $inscricaoMunicipal ?? $this->inscricaoMunicipal,
            $email ?? $this->email,
            $telefone ?? $this->telefone,
            $regimeTributario ?? $this->regimeTributario,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function paraApi(): array
    {
        $chaveDoDocumento = $this->documento->ehCnpj() ? 'CNPJ' : 'CPF';

        return [
            $chaveDoDocumento => $this->documento->digitos,
            'xNome' => $this->nome,
            'IM' => $this->inscricaoMunicipal,
            'email' => $this->email,
            'telefone' => preg_replace('/\D/', '', $this->telefone) ?? '',
            'regTrib' => $this->regimeTributario?->paraApi(),
            ...($this->endereco?->paraApi() ?? []),
        ];
    }
}
