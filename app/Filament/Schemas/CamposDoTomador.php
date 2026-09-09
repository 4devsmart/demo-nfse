<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use App\Domain\Enums\TipoPessoa;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * O cadastro do tomador, em dois blocos. Mora aqui, e nao no formulario do
 * recurso, porque a mesma coisa e preenchida em dois lugares: a tela de
 * clientes e o modal que o seletor da nota abre quando o tomador ainda nao
 * existe. Um cadastro so, escrito uma vez.
 */
final class CamposDoTomador
{
    /**
     * A explicação de cada bloco fica numa coluna ao lado dos campos, que é o
     * desenho das telas de cadastro. Num painel lateral essa coluna come metade
     * da largura para repetir o que o título do modal já disse, e os campos
     * ficam apertados a ponto de cortar o CNPJ. Lá ela sai, e sobra a linha do
     * título.
     *
     * @param  bool  $comExplicacao  a coluna de texto ao lado dos campos
     * @return array<int, Section>
     */
    public static function secoes(bool $comExplicacao = true): array
    {
        return array_map(
            static fn (Section $secao): Section => $comExplicacao
                ? $secao
                : $secao->aside(false)->description(null),
            [
                self::identificacao(),
                CamposDeEndereco::secao(),
            ],
        );
    }

    private static function identificacao(): Section
    {
        return Section::make(__('Identificação'))
            ->icon(Heroicon::OutlinedUser)
            ->description(__('O tomador do serviço: quem recebe a nota. Para pessoa jurídica, a lupa do CNPJ preenche razão social e endereço.'))
            ->aside()
            ->columns(12)
            ->schema([
                ToggleButtons::make('tipo_pessoa')
                    ->label(__('Tipo'))
                    ->options(TipoPessoa::class)
                    ->default(TipoPessoa::Juridica)
                    ->inline()
                    ->grouped()
                    ->live()
                    ->required()
                    ->columnSpan(12),

                Campos::documentoFederal()
                    ->suffixAction(ConsultaDeCnpj::botao())
                    ->columnSpan(4),

                TextInput::make('razao_social')
                    ->label(fn (Get $get): string => Campos::ehPessoaFisica($get) ? __('Nome') : __('Razão social'))
                    ->required()
                    ->columnSpan(8),

                // Escondido continua desidratando, com null. Sem isto, trocar
                // um tomador de PJ para PF nao levava o campo ao `$data`, e a
                // inscrição antiga sobrevivia na coluna.
                TextInput::make('inscricao_municipal')
                    ->label(__('Inscrição municipal'))
                    ->columnSpan(4)
                    ->visible(fn (Get $get): bool => ! Campos::ehPessoaFisica($get))
                    ->dehydratedWhenHidden()
                    ->dehydrateStateUsing(fn (Get $get, mixed $estado): ?string => Campos::ehPessoaFisica($get) || blank($estado)
                        ? null
                        : (string) $estado),
            ]);
    }
}
