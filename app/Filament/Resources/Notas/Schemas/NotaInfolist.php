<?php

declare(strict_types=1);

namespace App\Filament\Resources\Notas\Schemas;

use App\Consultas\BuscaDeCodigosDeServico;
use App\Filament\Resources\Empresas\EmpresaResource;
use App\Filament\Resources\Notas\NotaResource;
use App\Models\Nota;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;

/**
 * A tela abre respondendo "e agora?": o proximo passo vem antes de qualquer
 * dado. Depois, a esquerda o que a nota diz; a direita o que o provedor
 * devolveu, que e o que se guarda e o que se usa para recuperar.
 */
class NotaInfolist
{
    public static function configure(Schema $schema): Schema
    {
        // O container de uma pagina de visualizacao vem com 2 colunas. Sem
        // fixar em 12, o bloco abaixo ocupa metade da largura e a outra metade
        // fica vazia.
        return $schema
            ->columns(12)
            ->components([
                self::proximoPasso(),

                Group::make()
                    ->columnSpan(8)
                    ->schema([
                        self::partes(),
                        self::servico(),
                        self::valores(),
                    ]),

                Group::make()
                    ->columnSpan(4)
                    ->schema([
                        self::identificadores(),
                        self::retornoDoProvedor(),
                    ]),
            ]);
    }

    private static function proximoPasso(): Callout
    {
        return Callout::make(fn (Nota $nota): string => $nota->status->getLabel())
            ->description(fn (Nota $nota): string => $nota->orientacao())
            ->color(fn (Nota $nota): string => $nota->status->getColor())
            ->icon(fn (Nota $nota): Heroicon => $nota->status->getIcon())
            ->columnSpanFull()
            ->footerActions([
                Action::make('enviarCertificado')
                    ->label(__('Enviar certificado A1'))
                    ->icon(Heroicon::OutlinedKey)
                    ->link()
                    ->visible(fn (Nota $nota): bool => ! $nota->empresa->temCertificado())
                    ->url(fn (Nota $nota): string => EmpresaResource::getUrl('edit', ['record' => $nota->empresa])),

                // Depois de autorizada, o que se quer e o documento na mao e a
                // proxima nota, como na tela de sucesso do Emissor Nacional.
                Action::make('danfseDoSucesso')
                    ->label(__('Baixar DANFSE'))
                    ->icon(Heroicon::OutlinedDocumentCheck)
                    ->link()
                    ->visible(fn (Nota $nota): bool => $nota->temXmlAutorizado())
                    ->url(fn (Nota $nota): string => route('notas.danfse', $nota), shouldOpenInNewTab: true),

                Action::make('xmlDoSucesso')
                    ->label(__('Baixar XML'))
                    ->icon(Heroicon::OutlinedCodeBracketSquare)
                    ->link()
                    ->visible(fn (Nota $nota): bool => $nota->temXmlAutorizado())
                    ->url(fn (Nota $nota): string => route('notas.xml', ['nota' => $nota, 'documento' => 'nfse'])),

                Action::make('novaNota')
                    ->label(__('Nova NFS-e'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->link()
                    ->visible(fn (Nota $nota): bool => $nota->temXmlAutorizado())
                    ->url(fn (): string => NotaResource::getUrl('create')),
            ]);
    }

    private static function partes(): Section
    {
        return Section::make(__('Partes'))
            ->icon(Heroicon::OutlinedUsers)
            ->columns(2)
            ->schema([
                TextEntry::make('empresa.razao_social')
                    ->label(__('Emitente'))
                    ->icon(Heroicon::OutlinedBuildingOffice2)
                    ->helperText(fn (Nota $nota): string => $nota->empresa->documentoFederal()->formatado()
                        .' · '.$nota->empresa->cidade->nomeComUf()),

                TextEntry::make('cliente.razao_social')
                    ->label(__('Tomador'))
                    ->icon(Heroicon::OutlinedUser)
                    ->helperText(fn (Nota $nota): string => $nota->cliente->documentoFederal()->formatado()
                        .' · '.$nota->cliente->cidade->nomeComUf()),
            ]);
    }

    private static function servico(): Section
    {
        return Section::make(__('Serviço'))
            ->icon(Heroicon::OutlinedWrenchScrewdriver)
            ->columns(3)
            ->schema([
                TextEntry::make('competencia')->label(__('Competência'))->date('m/Y'),

                TextEntry::make('cidadePrestacao.nome')
                    ->label(__('Município da prestação'))
                    ->formatStateUsing(fn (Nota $nota): string => $nota->cidadePrestacao->nomeComUf()),

                // Seis digitos nao dizem nada sozinhos, e a discriminacao e o
                // que o emitente escreveu, nao o que a tabela nacional chama.
                TextEntry::make('codigo_servico')
                    ->label(__('Código do serviço'))
                    ->badge()
                    ->color('gray')
                    ->helperText(fn (Nota $nota): ?string => app(BuscaDeCodigosDeServico::class)
                        ->descricaoDoCodigo($nota->codigo_servico)),

                TextEntry::make('descricao_servico')
                    ->label(__('Discriminação'))
                    ->columnSpanFull(),

                TextEntry::make('cnae')->label(__('CNAE'))->placeholder('—'),
                TextEntry::make('item_lista_servico')->label(__('Item da lista — ABRASF'))->placeholder('—'),
            ]);
    }

    private static function valores(): Section
    {
        return Section::make(__('Valores'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->columns(4)
            ->schema([
                TextEntry::make('valor_servico')
                    ->label(__('Serviço'))
                    ->money('BRL')
                    ->weight('bold')
                    ->size('lg'),

                TextEntry::make('base_de_calculo')
                    ->label(__('Base de cálculo'))
                    ->state(fn (Nota $nota): string => $nota->valoresDoServico()->baseDeCalculo()->formatado()),

                TextEntry::make('issqn')
                    ->label(__('ISSQN'))
                    ->state(fn (Nota $nota): string => $nota->valoresDoServico()->issqnDevido()->formatado()),

                TextEntry::make('liquido')
                    ->label(__('Líquido'))
                    ->state(fn (Nota $nota): string => $nota->valoresDoServico()->valorLiquido()->formatado()),

                TextEntry::make('aliquota_iss')
                    ->label(__('Alíquota'))
                    ->formatStateUsing(fn (Nota $nota): string => $nota->aliquotaDoIss()->formatada()),

                TextEntry::make('tributacao_issqn')->label(__('Tributação'))->badge(),
                TextEntry::make('retencao_issqn')->label(__('Retenção'))->badge(),

                TextEntry::make('deducoes')
                    ->columnSpanFull()
                    ->label(__('Deduções e descontos'))
                    ->visible(fn (Nota $nota): bool => (float) $nota->deducoes > 0
                        || (float) $nota->desconto_incondicionado > 0
                        || (float) $nota->desconto_condicionado > 0)
                    ->state(fn (Nota $nota): string => collect([
                        __('deduções') => $nota->deducoes,
                        __('desc. incond.') => $nota->desconto_incondicionado,
                        __('desc. cond.') => $nota->desconto_condicionado,
                    ])
                        ->filter(fn (string $valor): bool => (float) $valor > 0)
                        ->map(fn (string $valor, string $rotulo): string => "{$rotulo} R$ ".number_format((float) $valor, 2, ',', '.'))
                        ->implode(' · ')),
            ]);
    }

    /**
     * Cada campo aparece quando passa a existir. Antes da transmissao, mostrar
     * cinco tracos seguidos nao informa nada, so ocupa a coluna.
     */
    private static function identificadores(): Section
    {
        return Section::make(__('Documento fiscal'))
            ->icon(Heroicon::OutlinedFingerPrint)
            ->description(__('O que a API devolveu e o que este sistema guarda.'))
            ->columns(2)
            ->schema([
                TextEntry::make('status')->label(__('Situação'))->badge(),
                TextEntry::make('ambiente')->label(__('Ambiente'))->badge(),

                TextEntry::make('numero')
                    ->label(__('DPS'))
                    ->formatStateUsing(fn (Nota $nota): string => "série {$nota->serie} · nº {$nota->numero}"),

                TextEntry::make('provedor')
                    ->label(__('Provedor'))
                    ->placeholder('—')
                    ->visible(fn (Nota $nota): bool => filled($nota->provedor)),

                TextEntry::make('id_dps')
                    ->label('id_dps')
                    ->copyable()
                    ->fontFamily(FontFamily::Mono)
                    ->size('xs')
                    ->columnSpanFull()
                    ->helperText(__('Determinístico. É ele que recupera uma transmissão perdida.'))
                    ->visible(fn (Nota $nota): bool => filled($nota->id_dps)),

                TextEntry::make('numero_nfse')
                    ->label(__('Número da NFS-e'))
                    ->fontFamily(FontFamily::Mono)
                    ->visible(fn (Nota $nota): bool => filled($nota->numero_nfse)),

                TextEntry::make('codigo_verificacao')
                    ->label(__('Cód. de verificação'))
                    ->visible(fn (Nota $nota): bool => filled($nota->codigo_verificacao)),

                TextEntry::make('chave')
                    ->label(__('Chave de acesso'))
                    ->copyable()
                    ->fontFamily(FontFamily::Mono)
                    ->size('xs')
                    ->columnSpanFull()
                    ->visible(fn (Nota $nota): bool => filled($nota->chave)),

                TextEntry::make('protocolo')
                    ->label(__('Protocolo'))
                    ->visible(fn (Nota $nota): bool => filled($nota->protocolo)),

                TextEntry::make('transmitida_em')
                    ->label(__('Transmitida em'))
                    ->dateTime('d/m/Y H:i')
                    ->visible(fn (Nota $nota): bool => $nota->transmitida_em !== null),

                TextEntry::make('cancelada_em')
                    ->label(__('Cancelada em'))
                    ->dateTime('d/m/Y H:i')
                    ->visible(fn (Nota $nota): bool => $nota->cancelada_em !== null),
            ]);
    }

    private static function retornoDoProvedor(): Section
    {
        return Section::make(__('Mensagens do provedor'))
            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
            ->visible(fn (Nota $nota): bool => filled($nota->mensagens) || filled($nota->motivo_cancelamento))
            ->columns(1)
            ->schema([
                TextEntry::make('mensagens')
                    ->hiddenLabel()
                    ->placeholder('—')
                    ->formatStateUsing(fn (Nota $nota): string => $nota->mensagensDoProvedor()->emLinhas()),

                TextEntry::make('motivo_cancelamento')
                    ->label(__('Motivo do cancelamento'))
                    ->placeholder('—')
                    ->visible(fn (Nota $nota): bool => filled($nota->motivo_cancelamento)),
            ]);
    }
}
