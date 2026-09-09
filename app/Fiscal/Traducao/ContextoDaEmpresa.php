<?php

declare(strict_types=1);

namespace App\Fiscal\Traducao;

use App\Domain\Enums\Ambiente;
use App\Fiscal\Pedidos\ContextoDoProvedor;
use App\Models\Empresa;

/**
 * Monta o contexto que acompanha toda chamada de NFS-e a partir do cadastro da
 * empresa. O certificado sai do banco cifrado e so aqui volta a ser certificado.
 */
final readonly class ContextoDaEmpresa
{
    public function montar(Empresa $empresa, ?Ambiente $ambiente = null): ContextoDoProvedor
    {
        $contexto = ContextoDoProvedor::novo(
            municipio: $empresa->municipio(),
            ambiente: $ambiente ?? $empresa->ambiente,
            cnpjDoEmitente: $empresa->documentoFederal(),
            inscricaoMunicipal: (string) $empresa->inscricao_municipal,
            razaoSocial: $empresa->razao_social,
            certificado: $empresa->certificado(),
        );

        $credenciais = $empresa->credenciaisDaPrefeitura();

        return $credenciais === null ? $contexto : $contexto->com($credenciais);
    }
}
