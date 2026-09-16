<?php

declare(strict_types=1);

namespace App\Filament\Suporte;

use App\Domain\Notas\ImpedimentosDaNota;
use App\Fiscal\Excecoes\DesfechoIndeterminado;
use App\Fiscal\Excecoes\FalhaFiscal;
use App\Fiscal\Respostas\EventoRegistrado;
use App\Fiscal\Respostas\RespostaCrua;
use App\Models\Nota;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Throwable;

/**
 * O que toda ação fiscal na tela precisa fazer igual: bloquear com um motivo
 * legível, executar, e transformar o desfecho em aviso.
 */
final class OperacaoFiscal
{
    private const LIMITE_DO_RETORNO_CRU = 400;

    /**
     * Desabilita a ação quando há impedimento e mostra o motivo no tooltip.
     * O botão continua na tela: ação que some deixa quem opera procurando o
     * que não há.
     *
     * @param  Closure(ImpedimentosDaNota): ?string  $impedimento
     */
    public static function bloqueadaQuando(Action $acao, Closure $impedimento): Action
    {
        return $acao
            ->disabled(fn (Nota $nota): bool => $impedimento($nota->impedimentos()) !== null)
            ->tooltip(fn (Nota $nota): ?string => $impedimento($nota->impedimentos()));
    }

    /**
     * Executa e avisa. O desfecho indeterminado tem tratamento próprio porque
     * ele é o único que NÃO autoriza repetir.
     *
     * @param  callable(): mixed  $chamada  o que a ação faz; o retorno é ignorado
     * @param  (callable(): mixed)|null  $aoConcluir  só roda se nada foi lançado
     */
    public static function executar(callable $chamada, ?callable $aoConcluir = null): void
    {
        try {
            $chamada();
        } catch (DesfechoIndeterminado $falha) {
            self::avisarDesfechoIndeterminado($falha);

            return;
        } catch (Throwable $falha) {
            self::avisarFalha($falha);

            return;
        }

        if ($aoConcluir !== null) {
            $aoConcluir();
        }
    }

    /**
     * O estado em que a nota ficou depois de transmitir, com o que o provedor
     * respondeu.
     */
    public static function avisarDesfechoDaNota(Nota $nota): void
    {
        // Lote em processamento não é sucesso nem recusa: o aviso diz o que
        // fazer, e não pinta de vermelho um envio que chegou.
        if ($nota->status->aguardaLote()) {
            Notification::make()
                ->info()
                ->title($nota->status->getLabel())
                ->body(__(':passo Protocolo :protocolo.', ['passo' => $nota->status->proximoPasso(), 'protocolo' => $nota->protocolo]))
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->status($nota->status->getColor() === 'success' ? 'success' : 'danger')
            ->title($nota->status->getLabel())
            ->body($nota->mensagensDoProvedor()->emLinhas() ?: $nota->identificacao())
            ->persistent()
            ->send();
    }

    /**
     * Consultas devolvem o retorno da biblioteca sem reinterpretação: o formato
     * varia por provedor. Mostrar cru é honesto, inventar estrutura não seria.
     *
     * O corpo do aviso é renderizado como HTML (`str($body)->sanitizeHtml()` no
     * Filament), e no Padrão Nacional o retorno traz a NFS-e inteira depois de
     * `XmlRetorno=`. Sem escapar, o sanitizador comia o XML tag por tag e a
     * consulta bem-sucedida aparecia terminando em `XmlRetorno=` e mais nada:
     * o operador via vazio onde o provedor tinha respondido o documento todo.
     */
    public static function avisarRetornoCru(RespostaCrua $resposta): void
    {
        Notification::make()
            ->status($resposta->foiSucesso() ? 'success' : 'warning')
            ->title(__('Retorno do provedor (código :codigo)', ['codigo' => $resposta->codigo]))
            ->body(e(str($resposta->resposta)->limit(self::LIMITE_DO_RETORNO_CRU)->toString()))
            ->persistent()
            ->send();
    }

    public static function avisarEvento(EventoRegistrado $evento, string $aoConcluir, string $aoRecusar): void
    {
        Notification::make()
            ->status($evento->foiConcluido() ? 'success' : 'danger')
            ->title($evento->foiConcluido() ? $aoConcluir : $aoRecusar)
            ->body($evento->mensagens->emLinhas() ?: __('Protocolo :protocolo', ['protocolo' => $evento->protocolo]))
            ->persistent()
            ->send();
    }

    /**
     * Vale para as três rotas que gravam no provedor, e o caminho de volta
     * muda com o que já se sabe da nota: o `id_dps` responde por uma DPS
     * transmitida, a chave por uma nota que já existia, série e número pela
     * substituta que nunca chegou a ter nenhum dos dois.
     */
    private static function avisarDesfechoIndeterminado(DesfechoIndeterminado $falha): void
    {
        Notification::make()
            ->warning()
            ->persistent()
            ->title(__('Desfecho indeterminado'))
            ->body(__(
                'O documento PODE ter sido registrado no provedor. Não repita a operação: '
                .'pergunte antes, por "Consultar DPS", "Consultar no provedor" ou "Consultar por RPS", '
                .'o que estiver disponível nesta nota. :resumo',
                ['resumo' => $falha->resumo()],
            ))
            ->send();
    }

    private static function avisarFalha(Throwable $falha): void
    {
        Notification::make()
            ->danger()
            ->persistent()
            ->title(__('A chamada não passou'))
            ->body($falha instanceof FalhaFiscal ? self::corpoDaFalha($falha) : $falha->getMessage())
            ->send();
    }

    /**
     * O resumo diz o que houve; a linha seguinte diz o que fazer, que é a
     * pergunta de quem está com a nota parada.
     *
     * `oQueFazer()` responde por código. Código que esta versão não conhece
     * fica sem orientação própria, e aí vale o lado seguro: repetir uma
     * transmissão que chegou duplicaria documento fiscal, e o custo de
     * consultar antes é um clique.
     */
    private static function corpoDaFalha(FalhaFiscal $falha): string
    {
        $orientacao = $falha->oQueFazer() ?: __('Não repita sem antes consultar o documento no provedor.');

        return $falha->resumo()."\n".$orientacao;
    }
}
