<?php

declare(strict_types=1);

namespace App\Actions\Enderecos;

use App\Domain\ValueObjects\CodigoIbge;

/**
 * O endereco que uma consulta devolve. `municipio` e o codigo IBGE, o mesmo que
 * decide o provedor de NFS-e, entao a cidade sai preenchida junto.
 *
 * Os tres ultimos campos nascem vazios porque o CEP nao sabe deles. Quem os
 * preenche e a consulta de CNPJ, que devolve o endereco completo da Receita, e
 * assim as duas consultas entregam o mesmo tipo para a tela aplicar.
 */
final readonly class EnderecoEncontrado
{
    public function __construct(
        public string $logradouro,
        public string $bairro,
        public string $localidade,
        public string $uf,
        public ?CodigoIbge $municipio,
        public string $cep = '',
        public string $numero = '',
        public string $complemento = '',
    ) {}
}
