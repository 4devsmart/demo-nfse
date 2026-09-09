<?php

declare(strict_types=1);

namespace App\Actions\Notas;

use App\Fiscal\Contracts\GatewayFiscal;
use App\Models\Nota;
use LogicException;

/**
 * O DANFSE sai do XML autorizado: render local, sem certificado e sem falar com
 * a prefeitura. E por isso que guardar o XML importa.
 */
final readonly class BaixarDanfse
{
    public function __construct(private GatewayFiscal $gateway) {}

    public function executar(Nota $nota): string
    {
        $this->exigirXmlAutorizado($nota);

        return $this->gateway
            ->gerarDanfse((string) $nota->xml_autorizado, $nota->empresa->municipio())
            ->conteudo();
    }

    private function exigirXmlAutorizado(Nota $nota): void
    {
        $impedimento = $nota->impedimentos()->paraImprimir();

        if ($impedimento !== null) {
            throw new LogicException($impedimento);
        }
    }
}
