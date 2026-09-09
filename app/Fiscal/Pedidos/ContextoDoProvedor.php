<?php

declare(strict_types=1);

namespace App\Fiscal\Pedidos;

use App\Domain\Enums\Ambiente;
use App\Domain\ValueObjects\CodigoIbge;
use App\Domain\ValueObjects\DocumentoFederal;
use App\Fiscal\Certificado\CertificadoDigital;

/**
 * O que toda rota de NFS-e repete: municipio (que decide o provedor), ambiente,
 * emitente e certificado. A API nao guarda cadastro, entao isto viaja em cada
 * chamada, e por isso vale ter um tipo so para ele.
 */
final readonly class ContextoDoProvedor
{
    private function __construct(
        private CodigoIbge $municipio,
        private Ambiente $ambiente,
        private DocumentoFederal $cnpjDoEmitente,
        private string $inscricaoMunicipal,
        private string $razaoSocial,
        private CertificadoDigital $certificado,
        private ?CredenciaisDaPrefeitura $credenciais,
    ) {}

    public static function novo(
        CodigoIbge $municipio,
        Ambiente $ambiente,
        DocumentoFederal $cnpjDoEmitente,
        string $inscricaoMunicipal,
        string $razaoSocial,
        CertificadoDigital $certificado,
    ): self {
        return new self($municipio, $ambiente, $cnpjDoEmitente, $inscricaoMunicipal, $razaoSocial, $certificado, null);
    }

    public function com(CredenciaisDaPrefeitura $credenciais): self
    {
        return new self(
            $this->municipio, $this->ambiente, $this->cnpjDoEmitente,
            $this->inscricaoMunicipal, $this->razaoSocial, $this->certificado, $credenciais,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function paraApi(): array
    {
        return array_filter([
            'municipio' => (string) $this->municipio,
            'ambiente' => $this->ambiente->value,
            'emitente' => array_filter([
                'cnpj' => $this->cnpjDoEmitente->digitos,
                'inscricao_municipal' => $this->inscricaoMunicipal,
                'razao_social' => $this->razaoSocial,
            ], static fn (string $valor): bool => $valor !== ''),
            'certificado' => $this->certificado->paraApi(),
            'credenciais' => $this->credenciais?->paraApi(),
        ], static fn (mixed $valor): bool => $valor !== null && $valor !== []);
    }
}
