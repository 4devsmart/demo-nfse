<?php

declare(strict_types=1);

namespace App\Domain\Notas;

use App\Domain\Enums\RetencaoIssqn;

/**
 * Traduz as respostas de tributação do formulário ("há retenção?", "está
 * suspensa?") nas colunas que elas governam.
 *
 * A resposta "Não" precisa apagar o que já estava gravado, e o formulário
 * sozinho não consegue dizer isso: os campos que a pergunta esconde não chegam
 * ao `$data`, porque o Filament não desidrata componente escondido. Sem esta
 * tradução, responder "Não" numa nota que já tem suspensão salva sem erro e
 * deixa a coluna intacta, e a DPS seguinte sai com a suspensão que o usuário
 * tirou. Benefício errado é base errada, que é ISSQN errado transmitido.
 *
 * A resposta em si não é coluna: depois de aplicada, some.
 */
final readonly class TributacaoRespondida
{
    /**
     * A pergunta e as colunas que o "Não" dela anula.
     *
     * @var array<string, list<string>>
     */
    private const APAGA = [
        'tem_suspensao' => ['tipo_suspensao', 'numero_processo_suspensao'],
        'tem_beneficio' => ['numero_beneficio_municipal', 'percentual_reducao_base'],
        'tem_totais' => [
            'total_tributos_federais', 'total_tributos_estaduais', 'total_tributos_municipais',
            'percentual_simples_nacional',
        ],
        'tem_retencao_federal' => ['cst_pis_cofins'],
        'tem_ibs_cbs' => [
            'cst_ibs_cbs', 'indicador_de_operacao', 'classificacao_tributaria', 'codigo_credito_presumido',
            'cidade_incidencia_ibs_cbs_id',
        ],
    ];

    /**
     * As colunas que o "Não" zera em vez de anular. São as alíquotas de
     * retenção federal, que a migration criou `NOT NULL` com padrão zero:
     * gravar `null` nelas seria erro de banco, não campo em branco.
     *
     * @var array<string, list<string>>
     */
    private const ZERA = [
        'tem_retencao_federal' => [
            'aliquota_pis', 'aliquota_cofins', 'aliquota_csll',
            'aliquota_irrf', 'aliquota_previdenciaria',
        ],
    ];

    /**
     * Retenção do ISSQN não zera: ela tem um valor próprio para "não retido", e
     * é ele que a coluna precisa guardar.
     */
    private const RETENCAO_ISSQN = 'tem_retencao';

    /**
     * A pergunta sobre retenção federal: quais das três contribuições sociais o
     * tomador retém. Vem da tela como lista marcada, e vira três booleanos,
     * porque é assim que a NT 007 pergunta pelo `tpRetPisCofins`.
     */
    private const CONTRIBUICOES_RETIDAS = 'contribuicoes_retidas';

    /**
     * @var array<string, string>
     */
    private const CONTRIBUICOES = [
        'pis' => 'retem_pis',
        'cofins' => 'retem_cofins',
        'csll' => 'retem_csll',
    ];

    private const RETENCAO_FEDERAL = 'tem_retencao_federal';

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public static function aplicadaEm(array $dados): array
    {
        foreach (self::APAGA as $pergunta => $colunas) {
            if (self::respostaFoiNao($dados, $pergunta)) {
                $dados = self::preencher($dados, $colunas, null);
            }
        }

        foreach (self::ZERA as $pergunta => $colunas) {
            if (self::respostaFoiNao($dados, $pergunta)) {
                $dados = self::preencher($dados, $colunas, 0);
            }
        }

        $dados = self::comContribuicoesRetidas($dados);

        if (self::respostaFoiNao($dados, self::RETENCAO_ISSQN)) {
            $dados['retencao_issqn'] = RetencaoIssqn::NaoRetido->value;
        }

        return array_diff_key($dados, [
            ...self::APAGA,
            ...self::ZERA,
            self::RETENCAO_ISSQN => null,
            self::CONTRIBUICOES_RETIDAS => null,
        ]);
    }

    /**
     * A lista marcada vira as três colunas. O "Não" na pergunta que a esconde
     * tem a última palavra: campo escondido não chega ao `$data`, e sem esta
     * regra a nota manteria a retenção que o usuário acabou de tirar.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private static function comContribuicoesRetidas(array $dados): array
    {
        if (self::respostaFoiNao($dados, self::RETENCAO_FEDERAL)) {
            return self::preencher($dados, array_values(self::CONTRIBUICOES), false);
        }

        if (! array_key_exists(self::CONTRIBUICOES_RETIDAS, $dados)) {
            return $dados;
        }

        $marcadas = (array) $dados[self::CONTRIBUICOES_RETIDAS];

        foreach (self::CONTRIBUICOES as $marcacao => $coluna) {
            $dados[$coluna] = in_array($marcacao, $marcadas, true);
        }

        return $dados;
    }

    /**
     * @param  array<string, mixed>  $dados
     * @param  list<string>  $colunas
     * @return array<string, mixed>
     */
    private static function preencher(array $dados, array $colunas, mixed $valor): array
    {
        foreach ($colunas as $coluna) {
            $dados[$coluna] = $valor;
        }

        return $dados;
    }

    /**
     * Pergunta ausente é atualização parcial (de um job, de um comando, de um
     * teste), e aí não há resposta a aplicar. Só o "Não" explícito apaga.
     *
     * @param  array<string, mixed>  $dados
     */
    private static function respostaFoiNao(array $dados, string $pergunta): bool
    {
        return array_key_exists($pergunta, $dados) && ! $dados[$pergunta];
    }
}
