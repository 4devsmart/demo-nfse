<?php

declare(strict_types=1);

namespace App\Filament\Resources\Notas\Acoes;

use App\Actions\Notas\CancelarNota;
use App\Actions\Notas\SubstituirNota;
use App\Filament\Schemas\Campos;
use App\Filament\Suporte\OperacaoFiscal;
use App\Fiscal\Pedidos\MotivoDoCancelamento;
use App\Models\Nota;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;

/**
 * O que fazer quando a nota saiu errada. São três respostas, e a diferença
 * entre elas é o quanto já saiu:
 *
 *   - nada saiu           → editar, e a DPS montada é descartada;
 *   - a nota foi autorizada → cancelar, ou substituir por outra corrigida.
 */
final class AcoesDeCorrecao
{
    private const TAMANHO_MINIMO_DO_MOTIVO = 15;

    private const TAMANHO_MAXIMO_DO_MOTIVO = 255;

    /**
     * Chamada única: a biblioteca não expõe o XML do evento antes de enviá-lo.
     * Se o cancelamento se perder, a recuperação é consultar a nota.
     */
    public static function cancelar(): Action
    {
        $acao = Action::make('cancelar')
            ->label(__('Cancelar nota'))
            ->icon(Heroicon::OutlinedArchiveBoxXMark)
            ->color('danger')
            ->visible(fn (Nota $nota): bool => $nota->status->permiteCancelar())
            ->schema([self::campoDoMotivo(__('Motivo do cancelamento'))])
            ->action(fn (Nota $nota, array $data, CancelarNota $cancelar) => OperacaoFiscal::executar(
                fn () => OperacaoFiscal::avisarEvento(
                    $cancelar->executar($nota, MotivoDoCancelamento::descrito((string) $data['motivo'])),
                    aoConcluir: __('Nota cancelada'),
                    aoRecusar: __('Cancelamento recusado'),
                ),
            ));

        return OperacaoFiscal::bloqueadaQuando($acao, fn ($impedimentos): ?string => $impedimentos->paraCancelar());
    }

    /**
     * Troca a nota por outra corrigida: a substituta nasce como cópia da
     * original e o evento leva as duas.
     *
     * Nenhum provedor testado oferece este webservice, o Padrão Nacional faz
     * substituição por outro caminho, que esta API não expõe. Quando a recusa
     * vem, a substituta fica como rascunho com as correções já digitadas.
     */
    public static function substituir(): Action
    {
        $acao = Action::make('substituir')
            ->label(__('Substituir'))
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->color('warning')
            ->modalHeading(__('Substituir esta NFS-e'))
            ->modalDescription(__('Emite uma nota nova referenciando esta, que passa a constar como substituída. Nem todo provedor implementa: o Padrão Nacional faz substituição por outro caminho, que esta API não expõe.'))
            ->modalSubmitActionLabel(__('Substituir'))
            ->visible(fn (Nota $nota): bool => $nota->status->permiteCancelar() && filled($nota->numero_nfse))
            ->schema([
                self::campoDoMotivo(__('Motivo da substituição')),

                Campos::dinheiro('valor_servico', __('Valor corrigido do serviço'))
                    ->required()
                    ->default(fn (Nota $nota): string => (string) $nota->valor_servico),

                Textarea::make('descricao_servico')
                    ->label(__('Discriminação corrigida'))
                    ->required()
                    ->rows(3)
                    ->default(fn (Nota $nota): string => $nota->descricao_servico),
            ])
            ->action(fn (Nota $nota, array $data, SubstituirNota $substituir) => OperacaoFiscal::executar(
                fn () => OperacaoFiscal::avisarEvento(
                    $substituir->executar(
                        $nota,
                        [
                            'valor_servico' => $data['valor_servico'],
                            'descricao_servico' => $data['descricao_servico'],
                        ],
                        MotivoDoCancelamento::descrito((string) $data['motivo']),
                    ),
                    aoConcluir: __('Nota substituída'),
                    aoRecusar: __('Substituição recusada'),
                ),
            ));

        return OperacaoFiscal::bloqueadaQuando($acao, fn ($impedimentos): ?string => $impedimentos->paraSubstituir());
    }

    /**
     * Enquanto nada saiu com sucesso, a nota ainda é um rascunho que por acaso
     * tem XML montado. Alterar descarta essa DPS: ela descreve a nota anterior.
     */
    public static function editar(): Action
    {
        return EditAction::make()
            ->label(__('Editar'))
            ->visible(fn (Nota $nota): bool => $nota->status->permiteEditar());
    }

    /**
     * O Padrao Nacional exige de 15 a 255 caracteres no motivo, e recusa fora
     * disso. O minimo ja era cobrado; o maximo nao, e o texto longo demais so
     * era descoberto na rejeicao, depois de a chamada sair.
     */
    private static function campoDoMotivo(string $rotulo): Textarea
    {
        return Textarea::make('motivo')
            ->label($rotulo)
            ->required()
            ->minLength(self::TAMANHO_MINIMO_DO_MOTIVO)
            ->maxLength(self::TAMANHO_MAXIMO_DO_MOTIVO)
            ->rows(3)
            ->helperText(__('De :minimo a :maximo caracteres. O provedor guarda este texto junto do evento.', [
                'minimo' => self::TAMANHO_MINIMO_DO_MOTIVO,
                'maximo' => self::TAMANHO_MAXIMO_DO_MOTIVO,
            ]));
    }
}
