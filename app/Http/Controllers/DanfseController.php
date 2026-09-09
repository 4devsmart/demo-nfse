<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Notas\BaixarDanfse;
use App\Fiscal\Excecoes\FalhaFiscal;
use App\Models\Nota;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * O impresso da nota, desenhado pela API a partir do XML autorizado. Vai inline
 * para abrir na aba: quem quer o arquivo usa o visualizador do proprio
 * navegador, e quem so quer conferir nao precisa baixar nada.
 *
 * Nao existe impresso antes da autorizacao. A biblioteca fiscal recusa desenhar
 * a partir da DPS ("Nenhum provedor selecionado") e a propria API documenta o
 * campo como "XML autorizado da NFS-e": numero, codigo de verificacao e data de
 * emissao so nascem quando o provedor autoriza, antes disso nao ha o que
 * imprimir.
 *
 * Quem desenha e a API, entao falha dela e desfecho normal desta rota, e nao
 * defeito deste sistema. Sem o `catch` abaixo o operador recebia a pagina de
 * erro do Laravel com a pilha inteira, e em producao, com `APP_DEBUG` desligado,
 * recebia "Server Error" e mais nada. O resumo e a orientacao da propria
 * `FalhaFiscal` sao o que ele pode usar.
 */
final class DanfseController extends Controller
{
    public function __construct(private readonly BaixarDanfse $baixarDanfse) {}

    public function __invoke(Nota $nota): Response
    {
        if (! $nota->temXmlAutorizado()) {
            abort(Status::HTTP_NOT_FOUND, __('O DANFSE só existe depois que o provedor autoriza a nota.'));
        }

        try {
            $impresso = $this->baixarDanfse->executar($nota);
        } catch (FalhaFiscal $falha) {
            abort(Status::HTTP_BAD_GATEWAY, $this->motivo($falha));
        }

        return new Response($impresso, Status::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="danfse-'.$nota->numero_nfse.'.pdf"',
        ]);
    }

    /**
     * A mesma leitura que `OperacaoFiscal` faz nas acoes da tela: o resumo diz o
     * que houve, a linha seguinte diz o que fazer. O XML autorizado continua
     * baixavel, e para conferir a nota ele basta.
     */
    private function motivo(FalhaFiscal $falha): string
    {
        $orientacao = $falha->oQueFazer()
            ?: __('O XML autorizado continua disponível para download e conferência.');

        return $falha->resumo()."\n".$orientacao;
    }
}
