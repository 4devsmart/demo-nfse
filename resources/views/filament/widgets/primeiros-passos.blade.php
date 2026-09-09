@use('Filament\Support\Icons\Heroicon')

<x-filament-widgets::widget>
    <x-filament::section
        :icon="Heroicon::OutlinedRocketLaunch"
        :heading="__('Antes de emitir')"
        :description="__('Quatro cadastros e a primeira NFS-e sai. O que já está pronto aparece marcado.')"
    >
        <div class="nfse-resumo">
            @foreach ($passos as $passo)
                <div class="nfse-passo">
                    <p class="nfse-passo__titulo">
                        <x-filament::icon
                            :icon="$passo->icone()"
                            class="nfse-passo__ordem"
                        />
                        {{ $passo->titulo }}
                    </p>

                    <p class="nfse-passo__texto">{{ $passo->explicacao }}</p>

                    @unless ($passo->concluido)
                        <p class="nfse-passo__texto">
                            <x-filament::link :href="$passo->url">{{ $passo->rotulo }}</x-filament::link>
                        </p>
                    @endunless
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
