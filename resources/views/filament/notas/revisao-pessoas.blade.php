<div class="nfse-resumo">
    @foreach ($partes as $parte)
        <div class="nfse-resumo__parte">
            <span class="nfse-resumo__rotulo">{{ $parte['rotulo'] }}</span>
            <span class="nfse-resumo__valor">{{ $parte['valor'] }}</span>
            @if ($parte['apoio'])
                <span class="nfse-resumo__apoio">{{ $parte['apoio'] }}</span>
            @endif
        </div>
    @endforeach
</div>
