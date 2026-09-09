<?php

declare(strict_types=1);

namespace App\Fiscal\Excecoes;

/**
 * 502 na transmissao: a DPS PODE ter virado nota. Reenviar duplica documento
 * fiscal. O caminho e consultar pelo id_dps.
 */
final class DesfechoIndeterminado extends FalhaFiscal
{
    public const CODIGO = 'desfecho_indeterminado';
}
