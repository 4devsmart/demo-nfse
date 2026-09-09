<?php

declare(strict_types=1);

namespace App\Consultas;

/**
 * O que a nota herda do emitente quando ele e escolhido. Fica aqui para que o
 * formulario so precise aplicar o resultado, sem decidir nada.
 */
final readonly class PadroesDaEmpresa
{
    public function __construct(private EmpresasEmitentes $empresas) {}

    /**
     * O padrao do emitente que a tela ja abre escolhido. Existe porque
     * `afterStateUpdated` nao dispara no valor inicial de um seletor: sem isto o
     * formulario abriria com os campos vazios ate alguem trocar de emitente e
     * voltar.
     */
    public function doEmitentePadrao(string $campo): mixed
    {
        return $this->paraNota($this->empresas->padrao()?->getKey())[$campo] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function paraNota(int|string|null $empresaId): array
    {
        $empresa = $this->empresas->encontrar($empresaId);

        if ($empresa === null) {
            return [];
        }

        return [
            'cidade_prestacao_id' => $empresa->cidade_id,
            'codigo_servico' => $empresa->codigo_servico_padrao,
            'cnae' => $empresa->cnae_padrao,
            'item_lista_servico' => $empresa->item_lista_servico_padrao,
            'nbs' => $empresa->nbs_padrao,
            'aliquota_iss' => $empresa->aliquota_iss_padrao,

            // As aliquotas de retencao federal e a classificacao de IBS/CBS
            // sao copiadas, nao lidas na hora de montar a DPS: a nota guarda o
            // percentual que valia no dia em que foi emitida.
            'cst_pis_cofins' => $empresa->cst_pis_cofins_padrao?->value,
            'aliquota_pis' => $empresa->aliquota_pis_padrao,
            'aliquota_cofins' => $empresa->aliquota_cofins_padrao,
            'aliquota_csll' => $empresa->aliquota_csll_padrao,
            'aliquota_irrf' => $empresa->aliquota_irrf_padrao,
            'aliquota_previdenciaria' => $empresa->aliquota_previdenciaria_padrao,
            'cst_ibs_cbs' => $empresa->cst_ibs_cbs_padrao,
            'indicador_de_operacao' => $empresa->indicador_de_operacao_padrao,
            'classificacao_tributaria' => $empresa->classificacao_tributaria_padrao,
        ];
    }
}
