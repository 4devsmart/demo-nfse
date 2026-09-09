<?php

declare(strict_types=1);

use App\Http\Controllers\DanfseController;
use App\Http\Controllers\DocumentacaoFiscalController;
use App\Http\Controllers\XmlDaNotaController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// O middleware `auth` manda para a rota `login`; no painel ela e a do Filament.
Route::redirect('/login', '/admin/login')->name('login');

/*
 * A documentacao da API fiscal, espelhada. As rotas mantem os caminhos de
 * origem (`/docs`, `/docs/*` e `/openapi.yaml`) porque e assim que o HTML do
 * Swagger referencia os proprios arquivos, e nao ha o que reescrever.
 */
Route::middleware('auth')->group(function (): void {
    // O DANFSE desenhado pela API, a partir do XML autorizado.
    Route::get('/admin/notas/{nota}/danfse', DanfseController::class)->name('notas.danfse');

    // O XML cru: a DPS que foi enviada, a NFS-e que voltou autorizada e o
    // evento que veio depois, quando houve um.
    Route::get('/admin/notas/{nota}/xml/{documento}', XmlDaNotaController::class)
        ->name('notas.xml')
        ->where('documento', 'dps|nfse|evento');

    Route::get('/docs', [DocumentacaoFiscalController::class, 'pagina'])->name('fiscal.docs');
    // O nome precisa comecar por letra ou digito. Com o ponto liberado na
    // primeira posicao, `/docs/..` chega ao controlador e o espelho passa a
    // buscar a raiz da API em vez de um arquivo do Swagger. Nenhum arquivo do
    // Swagger comeca com ponto, entao a restricao nao custa nada.
    Route::get('/docs/{arquivo}', [DocumentacaoFiscalController::class, 'recurso'])
        ->where('arquivo', '[A-Za-z0-9][A-Za-z0-9._-]*');
    Route::get('/openapi.yaml', [DocumentacaoFiscalController::class, 'especificacao']);
});
