<?php

declare(strict_types=1);

namespace App\Filament\Resources\Notas\Pages;

use App\Filament\Resources\Notas\Acoes\AcoesDaNota;
use App\Filament\Resources\Notas\NotaResource;
use App\Models\Nota;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewNota extends ViewRecord
{
    protected static string $resource = NotaResource::class;

    public function getTitle(): string
    {
        return $this->obterNota()->identificacao();
    }

    public function getSubheading(): string
    {
        $nota = $this->obterNota();

        return "{$nota->empresa->razao_social} → {$nota->cliente->razao_social}";
    }

    /**
     * As ações que movem a nota adiante ficam soltas; as de consulta e
     * inspeção entram no menu. É o que separa "o que faço agora" de "o que
     * posso olhar".
     */
    protected function getHeaderActions(): array
    {
        return [
            // Emitir e "só gerar" sao a mesma bifurcacao: fazer os dois passos,
            // ou parar no primeiro para conferir. Com a DPS ja gerada, as duas
            // somem e sobra transmitir.
            AcoesDaNota::emitir(),
            AcoesDaNota::gerarDps(),
            AcoesDaNota::transmitir(),
            AcoesDaNota::consultarDps(),
            AcoesDaNota::consultarNoProvedor(),
            AcoesDaNota::baixarDanfse(),

            ActionGroup::make([
                AcoesDaNota::consultarPorRps(),
                AcoesDaNota::substituir(),
                AcoesDaNota::baixarXml(),
                AcoesDaNota::buscarXmlDoEvento(),
                AcoesDaNota::verPayload(),
                AcoesDaNota::verXml(),
                AcoesDaNota::editar(),
                AcoesDaNota::cancelar(),
            ])
                ->label(__('Mais'))
                ->icon(Heroicon::OutlinedEllipsisVertical)
                ->button()
                ->color('gray'),
        ];
    }

    /**
     * `getRecord()` é declarado como Model porque a página base serve a
     * qualquer recurso. Aqui sabemos qual é, e `assert` deixa isso escrito.
     */
    private function obterNota(): Nota
    {
        $registro = $this->getRecord();
        assert($registro instanceof Nota);

        return $registro;
    }
}
