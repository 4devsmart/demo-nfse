<?php

declare(strict_types=1);

namespace App\Domain\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * O ciclo de vida da nota dentro deste sistema. `Indeterminada` existe porque a
 * transmissao pode dar timeout depois de a prefeitura ter recebido a DPS: nesse
 * caso reenviar duplica documento fiscal, e o caminho e consultar pelo id_dps.
 */
enum StatusNota: string implements HasColor, HasIcon, HasLabel
{
    case Rascunho = 'rascunho';
    case DpsGerada = 'dps_gerada';
    case Autorizada = 'autorizada';
    case Rejeitada = 'rejeitada';
    case Indeterminada = 'indeterminada';
    case Cancelada = 'cancelada';
    case Substituida = 'substituida';

    public function getLabel(): string
    {
        return match ($this) {
            self::Rascunho => __('Rascunho'),
            self::DpsGerada => __('DPS gerada'),
            self::Autorizada => __('Autorizada'),
            self::Rejeitada => __('Rejeitada'),
            self::Indeterminada => __('Desfecho indeterminado'),
            self::Cancelada => __('Cancelada'),
            self::Substituida => __('Substituída'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Rascunho => 'gray',
            self::DpsGerada => 'info',
            self::Autorizada => 'success',
            self::Rejeitada => 'danger',
            self::Indeterminada => 'warning',
            self::Cancelada => 'danger',
            self::Substituida => 'warning',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Rascunho => Heroicon::OutlinedPencilSquare,
            self::DpsGerada => Heroicon::OutlinedDocumentText,
            self::Autorizada => Heroicon::OutlinedCheckBadge,
            self::Rejeitada => Heroicon::OutlinedXCircle,
            self::Indeterminada => Heroicon::OutlinedQuestionMarkCircle,
            self::Cancelada => Heroicon::OutlinedArchiveBoxXMark,
            self::Substituida => Heroicon::OutlinedArrowPathRoundedSquare,
        };
    }

    /**
     * O que este estado quer dizer, em uma frase. Alimenta o guia do fluxo.
     */
    public function significado(): string
    {
        return match ($this) {
            self::Rascunho => __('A nota existe só aqui. Nada foi montado nem enviado.'),
            self::DpsGerada => __('O XML da DPS está montado e guardado. Nada saiu para a prefeitura.'),
            self::Autorizada => __('O provedor autorizou. O XML protocolado está guardado aqui.'),
            self::Rejeitada => __('O provedor recusou e disse o motivo. Nada foi autorizado.'),
            self::Indeterminada => __('A transmissão não teve resposta. A nota PODE existir no provedor.'),
            self::Cancelada => __('A nota existiu e o evento de cancelamento foi registrado.'),
            self::Substituida => __('Foi trocada por outra nota, que a referencia.'),
        };
    }

    /**
     * A chamada da API que tira a nota deste estado. Null quando o estado e
     * final e nao ha para onde ir.
     */
    public function chamadaQueAvanca(): ?string
    {
        return match ($this) {
            self::Rascunho => 'POST /v1/nfse/xml',
            self::DpsGerada, self::Rejeitada => 'POST /v1/nfse/transmissao',
            self::Indeterminada => 'POST /v1/nfse/consulta-dps',
            self::Autorizada => 'POST /v1/nfse/eventos/cancelamento',
            self::Cancelada, self::Substituida => null,
        };
    }

    /**
     * O que fazer agora. E a frase que a tela mostra em destaque: quem abre uma
     * nota precisa saber o proximo passo sem decorar o fluxo fiscal.
     */
    public function proximoPasso(): string
    {
        return match ($this) {
            self::Rascunho => __('Gere a DPS para conferir o XML antes de transmitir, ou emita direto, que faz os dois passos.'),
            self::DpsGerada => __('A DPS está montada e nada saiu ainda. Transmita para o provedor do município.'),
            self::Autorizada => __('Autorizada. Guarde o XML: não há segunda via dele.'),
            self::Rejeitada => __('O provedor recusou. Corrija o que ele apontou abaixo e transmita de novo.'),
            self::Indeterminada => __('Não reenvie: a nota pode já existir. Use "Consultar DPS" para descobrir.'),
            self::Cancelada => __('Cancelada. Para refazer o serviço, abra uma nova nota.'),
            self::Substituida => __('Substituída. A nota que a substituiu está ligada a ela.'),
        };
    }

    /**
     * Enquanto nada saiu com sucesso, a nota e um rascunho que por acaso ja tem
     * XML montado. Depois de autorizada, cancelada ou com desfecho
     * indeterminado, editar seria mexer em documento que pode existir no
     * provedor, ai o caminho e cancelar e emitir outra.
     */
    public function permiteEditar(): bool
    {
        return in_array($this, [self::Rascunho, self::DpsGerada, self::Rejeitada], true);
    }

    public function permiteTransmitir(): bool
    {
        return in_array($this, [self::Rascunho, self::DpsGerada, self::Rejeitada], true);
    }

    public function permiteCancelar(): bool
    {
        return $this === self::Autorizada;
    }

    public function pedeConsulta(): bool
    {
        return $this === self::Indeterminada;
    }

    /**
     * Houve evento sobre esta nota. Sao os dois estados em que existe documento
     * de evento na fila DF-e: cancelamento e substituicao.
     */
    public function teveEvento(): bool
    {
        return in_array($this, [self::Cancelada, self::Substituida], true);
    }
}
