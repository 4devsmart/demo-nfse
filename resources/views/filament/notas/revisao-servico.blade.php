<div class="nfse-resumo">
    <div class="nfse-resumo__grade">
        @foreach ($campos as $rotulo => $valor)
            <div class="nfse-resumo__parte">
                <span class="nfse-resumo__rotulo">{{ $rotulo }}</span>
                <span class="nfse-resumo__valor">{{ $valor }}</span>
            </div>
        @endforeach
    </div>

    @if ($discriminacao)
        <div class="nfse-resumo__parte">
            <span class="nfse-resumo__rotulo">{{ __('Discriminação') }}</span>
            <p class="nfse-resumo__texto">{{ $discriminacao }}</p>
        </div>
    @endif
</div>
