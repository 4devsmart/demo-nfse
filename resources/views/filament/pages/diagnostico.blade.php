@use('Filament\Support\Icons\Heroicon')

@php($identificacao = $this->identificacao())

<x-filament-panels::page>
    @if ($identificacao === null)
        <x-filament::section :icon="Heroicon::OutlinedExclamationTriangle" icon-color="danger">
            <x-slot name="heading">{{ __('A API fiscal não respondeu') }}</x-slot>
            <x-slot name="description">
                {!! __('Confira se os serviços <code>fiscal-api</code> e <code>fiscal-worker</code> estão de pé e se o <code>FISCAL_API_TOKEN</code> confere dos dois lados.') !!}
            </x-slot>

            <p class="nfse-resultado__codigo">{{ $this->falha() }}</p>
        </x-filament::section>
    @else
        <x-filament::section :icon="Heroicon::OutlinedCube">
            <x-slot name="heading">{{ __('Build em execução') }}</x-slot>
            <x-slot name="description">
                {{ __('A API não guarda nada: nem o XML, nem o certificado. Quem guarda é esta aplicação.') }}
            </x-slot>

            <div class="nfse-dados">
                <div class="nfse-resumo__parte">
                    <span class="nfse-resumo__rotulo">{{ __('Commit') }}</span>
                    <span class="nfse-resumo__valor">{{ $identificacao->commit ?: '—' }}</span>
                </div>
                <div class="nfse-resumo__parte">
                    <span class="nfse-resumo__rotulo">{{ __('Build') }}</span>
                    <span class="nfse-resumo__valor">{{ $identificacao->build ?: '—' }}</span>
                </div>
                <div class="nfse-resumo__parte">
                    <span class="nfse-resumo__rotulo">{{ __('Prefixo das rotas') }}</span>
                    <span class="nfse-resumo__valor">{{ $identificacao->base }}</span>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section :icon="Heroicon::OutlinedSquares2x2">
            <x-slot name="heading">{{ __('O que esta build sabe fazer') }}</x-slot>
            <x-slot name="description">{{ __('Capacidades por módulo, como a própria API as declara.') }}</x-slot>

            <div class="nfse-capacidades">
                @foreach ($identificacao->modulos as $modulo => $rotas)
                    <div>
                        <p class="nfse-capacidades__modulo">{{ $modulo }}</p>
                        <div class="nfse-etiquetas">
                            @foreach ($rotas as $rota)
                                <x-filament::badge :color="$modulo === 'nfse' ? 'success' : 'gray'" size="sm">
                                    {{ $rota }}
                                </x-filament::badge>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    <x-filament::section :icon="Heroicon::OutlinedMapPin">
        <x-slot name="heading">{{ __('Quem atende um município?') }}</x-slot>
        <x-slot name="description">
            {{ __('O município decide o provedor e o layout, o pedido não escolhe. Consulte antes de montar qualquer coisa: é uma chamada que não leva certificado.') }}
        </x-slot>

        <form wire:submit="consultarMunicipio" class="nfse-busca">
            <div class="nfse-busca__campo">
                <label for="codigoDoMunicipio" class="nfse-busca__rotulo">{{ __('Código IBGE') }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input
                        id="codigoDoMunicipio"
                        type="text"
                        inputmode="numeric"
                        maxlength="7"
                        placeholder="3550308"
                        wire:model="codigoDoMunicipio"
                    />
                </x-filament::input.wrapper>
            </div>

            <x-filament::button type="submit" :icon="Heroicon::MagnifyingGlass">
                {{ __('Consultar') }}
            </x-filament::button>
        </form>

        <p class="nfse-passo__texto">
            {{ __('Ex.: 3550308 São Paulo · 3304557 Rio de Janeiro · 2927408 Salvador') }}
        </p>

        @if ($municipioConsultado)
            <div @class([
                'nfse-resultado',
                'nfse-resultado--suportado' => $municipioConsultado['suportado'],
                'nfse-resultado--ausente' => ! $municipioConsultado['suportado'],
            ])>
                <p class="nfse-resultado__codigo">{{ $municipioConsultado['codigo'] }}</p>

                @if ($municipioConsultado['suportado'])
                    <p class="nfse-resultado__titulo">{{ $municipioConsultado['provedor'] }}</p>
                    <p class="nfse-passo__texto">
                        {!! __('Layout <code class="nfse-codigo">:layout</code>. Emissão possível.', ['layout' => e($municipioConsultado['layout'])]) !!}
                    </p>
                @else
                    <p class="nfse-resultado__titulo">{{ __('Sem provedor de NFS-e conhecido') }}</p>
                    <p class="nfse-passo__texto">
                        {!! __('Montar a DPS para este município seria recusado com <code class="nfse-codigo">provedor_nao_suportado</code>.') !!}
                    </p>
                @endif
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
