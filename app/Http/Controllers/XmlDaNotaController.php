<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Nota;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * O XML, para baixar. Sao tres documentos diferentes e vale nao confundi-los:
 * a DPS e o que foi montado e enviado; a NFS-e e o que a prefeitura devolveu
 * autorizado; o evento e o que veio depois, cancelamento ou substituicao.
 *
 * O evento nao volta na consulta pela chave, que devolve a NFS-e como foi
 * autorizada: ele so existe na fila DF-e, e e de la que `BuscarXmlDoEvento` o
 * traz.
 */
final class XmlDaNotaController extends Controller
{
    private const DOCUMENTOS = ['dps', 'nfse', 'evento'];

    public function __invoke(Nota $nota, string $documento): Response
    {
        abort_unless(in_array($documento, self::DOCUMENTOS, true), Status::HTTP_NOT_FOUND);

        $conteudo = match ($documento) {
            'dps' => $this->conteudo($nota->xml_dps, __('A DPS ainda não foi montada.')),
            'evento' => $this->conteudo($nota->xml_evento, __('Esta nota não tem documento de evento guardado.')),
            default => $this->conteudo($nota->xml_autorizado, __('A nota ainda não foi autorizada.')),
        };

        return new Response($conteudo, Status::HTTP_OK, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$this->nomeDoArquivo($nota, $documento).'"',
        ]);
    }

    private function conteudo(?string $xmlEmBase64, string $motivo): string
    {
        abort_if(blank($xmlEmBase64), Status::HTTP_NOT_FOUND, $motivo);

        return base64_decode((string) $xmlEmBase64, true) ?: '';
    }

    private function nomeDoArquivo(Nota $nota, string $documento): string
    {
        $identificador = $documento === 'dps'
            ? ($nota->id_dps ?? "dps-{$nota->serie}-{$nota->numero}")
            : ($nota->chave ?? $nota->numero_nfse ?? 'nfse');

        return "{$documento}-{$identificador}.xml";
    }
}
