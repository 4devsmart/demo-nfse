<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Este projeto nao tem build de Tailwind, nem Node na imagem.
 * O CSS do Filament traz so as utilidades que ele proprio usa, entao classe
 * avulsa escrita numa view pode simplesmente nao existir, e o layout desmonta
 * sem erro nenhum: a tela renderiza, so fica crua.
 *
 * Este teste existe porque o defeito e silencioso.
 */
class EstiloProprioTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O único prefixo que este projeto escreve à mão. Está no cabeçalho do
     * `nfse.css`: "poucas classes, prefixadas com `nfse-` para nunca colidirem".
     */
    private const PREFIXO_PROPRIO = 'nfse-';

    /**
     * A lista de proibidos que este teste tinha antes não cumpria o que
     * prometia: eram dezessete prefixos escolhidos a dedo, e o
     * `payload.blade.php` passou anos por ela usando `bg-gray-950`,
     * `rounded-lg`, `overflow-x-auto` e `text-gray-100`, nenhum deles na lista
     * e nenhum deles existente no CSS servido. A regra de verdade é a inversa,
     * e é a que o `nfse.css` já enuncia: toda classe escrita numa view é do
     * projeto, ou não existe.
     */
    public function test_toda_classe_escrita_numa_view_e_do_projeto(): void
    {
        $problemas = [];

        foreach (File::allFiles(resource_path('views')) as $arquivo) {
            foreach (self::classesDe($arquivo->getContents()) as $classe) {
                if (! str_starts_with($classe, self::PREFIXO_PROPRIO)) {
                    $problemas[] = "{$arquivo->getRelativePathname()} usa \"{$classe}\"";
                }
            }
        }

        $this->assertSame(
            [],
            $problemas,
            "Sem build de Tailwind, classe fora do prefixo `nfse-` simplesmente não existe:\n  "
                .implode("\n  ", $problemas),
        );
    }

    /**
     * Vale para as duas formas de escrever classe numa Blade: o atributo
     * `class="..."` e o `@class([...])`, onde o nome é sempre o primeiro texto
     * entre aspas de cada linha do array.
     *
     * Token interpolado fica de fora: quem decide o valor é o PHP, e a asserção
     * seria sobre a expressão, não sobre a classe.
     *
     * @return list<string>
     */
    private static function classesDe(string $conteudo): array
    {
        preg_match_all('/\bclass="([^"]*)"/', $conteudo, $atributos);
        preg_match_all('/@class\(\[(.*?)\]\)/s', $conteudo, $arrays);

        /** @var list<string> $tokens */
        $tokens = [];

        foreach ($atributos[1] as $atributo) {
            $tokens = [...$tokens, ...(preg_split('/\s+/', trim($atributo), flags: PREG_SPLIT_NO_EMPTY) ?: [])];
        }

        foreach ($arrays[1] as $array) {
            preg_match_all("/^\s*'([^']+)'/m", $array, $nomes);
            $tokens = [...$tokens, ...$nomes[1]];
        }

        $proprias = [];

        foreach ($tokens as $token) {
            if (! str_contains($token, '{{') && ! str_contains($token, '$')) {
                $proprias[] = $token;
            }
        }

        return $proprias;
    }

    public function test_o_estilo_proprio_e_servido_pelo_painel(): void
    {
        $this->assertFileExists(public_path('css/app/nfse.css'), 'rode: php artisan filament:assets');

        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertOk()
            ->assertSee('css/app/nfse.css', escape: false);
    }
}
