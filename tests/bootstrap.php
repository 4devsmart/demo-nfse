<?php

declare(strict_types=1);

/*
 * O PHPUnit aplica o bloco <php><env> em $_ENV e em putenv(), mas nao em
 * $_SERVER, e e $_SERVER que o Laravel consulta primeiro. Dentro do container
 * as variaveis do compose estao em $_SERVER, entao sem esta ponte o
 * `php artisan test` rodaria com a configuracao de desenvolvimento, inclusive o
 * banco de desenvolvimento.
 */

require __DIR__.'/../vendor/autoload.php';

/*
 * O segundo caminho para o mesmo estrago, e este a ponte acima nao cobre.
 *
 * Config cacheada e lida do arquivo, e o Laravel nem olha para o ambiente:
 * `<env force="true">` deixa de valer inteiro. A suite passa a rodar com
 * CACHE_STORE=database e, o que custa caro, com o DB_DATABASE de
 * desenvolvimento no lugar de `:memory:`. Ai o `RefreshDatabase` roda
 * `migrate:fresh` no banco real e apaga os municipios, os cadastros e as notas.
 *
 * O estrago e silencioso: a suite fica verde enquanto destroi os dados. Por
 * isso aqui e um `exit`, e nao um aviso. `composer test` ja limpa a config
 * antes; os demais caminhos passam a limpar tambem, e este bloco existe para
 * quem chamar o `vendor/bin/phpunit` na mao.
 */
if (file_exists(__DIR__.'/../bootstrap/cache/config.php')) {
    fwrite(STDERR, <<<'TEXTO'

        A configuracao esta cacheada em bootstrap/cache/config.php.

        Com ela no lugar o phpunit.xml nao consegue trocar o banco por
        `:memory:`, e o RefreshDatabase apagaria o banco de desenvolvimento.
        A suite nao roda assim.

        Limpe antes:
          php artisan config:clear


        TEXTO);

    exit(1);
}

foreach ($_ENV as $variavel => $valor) {
    $_SERVER[$variavel] = $valor;
}
