<?php

declare(strict_types=1);

namespace App\Consultas;

use App\Models\Cidade;
use App\Models\Cliente;
use App\Models\Empresa;

/**
 * O que ainda falta para emitir. O painel usa isto para dizer ao recem-chegado
 * qual e o proximo cadastro, em vez de deixa-lo procurar no menu.
 */
final readonly class EstadoDaConfiguracao
{
    /**
     * @return list<PassoDaConfiguracao>
     */
    public function passos(): array
    {
        return [
            new PassoDaConfiguracao(
                titulo: __('Municípios do IBGE'),
                explicacao: __('É o código IBGE que decide o provedor de NFS-e do emitente.'),
                concluido: Cidade::query()->exists(),
                rotulo: __('Ver municípios'),
                url: '/admin/cidades',
            ),
            new PassoDaConfiguracao(
                titulo: __('Empresa emitente'),
                explicacao: __('Quem presta o serviço e assina a nota.'),
                concluido: Empresa::query()->exists(),
                rotulo: __('Cadastrar emitente'),
                url: '/admin/empresas/create',
            ),
            new PassoDaConfiguracao(
                titulo: __('Certificado A1'),
                explicacao: __('Sem ele dá para gerar a DPS, mas não para transmitir.'),
                concluido: Empresa::query()->whereNotNull('certificado_arquivo')->exists(),
                rotulo: __('Abrir emitente'),
                url: '/admin/empresas',
            ),
            new PassoDaConfiguracao(
                titulo: __('Cliente tomador'),
                explicacao: __('Quem recebe o serviço.'),
                concluido: Cliente::query()->exists(),
                rotulo: __('Cadastrar tomador'),
                url: '/admin/clientes',
            ),
        ];
    }

    public function estaCompleta(): bool
    {
        foreach ($this->passos() as $passo) {
            if (! $passo->concluido) {
                return false;
            }
        }

        return true;
    }
}
