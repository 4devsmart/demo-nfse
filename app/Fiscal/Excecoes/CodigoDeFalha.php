<?php

declare(strict_types=1);

namespace App\Fiscal\Excecoes;

/**
 * Os codigos que a API fiscal usa no envelope de erro. O contrato dela manda
 * tratar por codigo, nunca pela mensagem, entao eles viram tipo aqui, e nao
 * string espalhada por ifs.
 *
 * `ApiInacessivel` e o unico que nao vem da API: e o nosso, para quando a
 * chamada nem chegou a sair.
 */
enum CodigoDeFalha: string
{
    case NaoAutorizado = 'nao_autorizado';
    case JsonInvalido = 'json_invalido';
    case CampoObrigatorio = 'campo_obrigatorio';
    case CertificadoInvalido = 'certificado_invalido';
    case RegrasDeNegocio = 'regras_de_negocio';
    case ProvedorNaoSuportado = 'provedor_nao_suportado';
    case OperacaoNaoSuportada = 'operacao_nao_suportada';
    case GrupoIncompativel = 'grupo_incompativel';
    case LimiteDeRequisicoes = 'limite_de_requisicoes';
    case DesfechoIndeterminado = 'desfecho_indeterminado';
    case LibIndisponivel = 'lib_indisponivel';
    case FalhaNaLib = 'falha_na_lib';
    case ApiInacessivel = 'api_inacessivel';

    public function significado(): string
    {
        return match ($this) {
            self::NaoAutorizado => __('O Bearer não confere. A chamada foi recusada na autenticação, sem chegar ao motor fiscal.'),
            self::JsonInvalido => __('Corpo malformado, ou um campo com nome que a API não conhece.'),
            self::CampoObrigatorio => __('Faltou um campo que o documento exige.'),
            self::CertificadoInvalido => __('O A1 não abriu: senha errada, arquivo corrompido ou vencido.'),
            self::RegrasDeNegocio => __('A biblioteca fiscal reprovou o documento. Nada foi transmitido.'),
            self::ProvedorNaoSuportado => __('O município não tem provedor de NFS-e conhecido.'),
            self::OperacaoNaoSuportada => __('O provedor do município não implementa esta operação.'),
            self::GrupoIncompativel => __('Um grupo que não existe neste tipo de documento foi enviado.'),
            self::LimiteDeRequisicoes => __('Passou do teto de chamadas por minuto.'),
            self::DesfechoIndeterminado => __('A transmissão pode ter chegado ao provedor. Não se sabe.'),
            self::LibIndisponivel => __('O motor fiscal não respondeu. A chamada não saiu.'),
            self::FalhaNaLib => __('A biblioteca fiscal falhou no meio da operação.'),
            // Token errado nao cai aqui: nesse caso a API responde 401 com
            // `nao_autorizado`. Este codigo e para quando nao veio resposta.
            self::ApiInacessivel => __('A própria API não respondeu: rede fora do ar ou container parado.'),
        };
    }

    public function oQueFazer(): string
    {
        return match ($this) {
            self::NaoAutorizado => __('Confira o FISCAL_API_TOKEN: ele tem que ser o mesmo nesta aplicação e no container da API.'),
            self::JsonInvalido,
            self::CampoObrigatorio,
            self::GrupoIncompativel => __('Corrija o cadastro ou a nota e tente de novo.'),
            self::CertificadoInvalido => __('Envie o certificado outra vez, conferindo a senha.'),
            self::RegrasDeNegocio => __('Leia as rejeições, ajuste a nota e gere a DPS de novo.'),
            self::ProvedorNaoSuportado,
            self::OperacaoNaoSuportada => __('Não há o que tentar: o município ou o provedor não atende.'),
            self::LimiteDeRequisicoes => __('Espere os segundos indicados em Retry-After e repita.'),
            self::DesfechoIndeterminado => __('NÃO reenvie. Use "Consultar DPS": repetir duplicaria o documento.'),
            self::LibIndisponivel,
            self::ApiInacessivel => __('Repetir é seguro: nada saiu.'),
            self::FalhaNaLib => __('Avalie o retorno antes de repetir.'),
        };
    }

    /**
     * Repetir a mesma chamada e seguro? So quando se sabe que nada saiu.
     *
     * Seguro nao e o mesmo que util: `nao_autorizado` e `limite_de_requisicoes`
     * entram aqui porque a chamada foi recusada antes de executar qualquer
     * coisa, nao porque repetir sem mudar nada va dar outro resultado. O que
     * fazer de fato esta em `oQueFazer()`.
     */
    public function podeRepetir(): bool
    {
        return match ($this) {
            self::LibIndisponivel,
            self::LimiteDeRequisicoes,
            self::NaoAutorizado,
            self::ApiInacessivel => true,
            default => false,
        };
    }
}
