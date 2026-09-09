<?php

declare(strict_types=1);

namespace App\Fiscal\Respostas;

/**
 * Junta `/v1/ping` e `/v1/capacidades`: quem está rodando e o que esta build
 * sabe fazer, por módulo.
 *
 * É assim que se descobre o contrato sem tentar a rota e tomar 404.
 */
final readonly class IdentificacaoDaApi
{
    /**
     * @param  array<string, list<string>>  $modulos  nome do módulo => rotas que ele atende
     */
    public function __construct(
        public string $commit,
        public string $build,
        public string $base,
        public array $modulos,
    ) {}

    /**
     * @param  array<string, mixed>  $ping
     * @param  array<string, mixed>  $capacidades
     */
    public static function dasRespostas(array $ping, array $capacidades): self
    {
        $versao = new LeitorDaResposta($ping)->dentroDe('versao');
        $lidas = new LeitorDaResposta($capacidades);

        return new self(
            commit: $versao->texto('commit_curto'),
            build: $versao->texto('build'),
            base: $lidas->texto('base', '/v1'),
            modulos: array_map(
                static fn (mixed $rotas): array => array_values(array_map(strval(...), (array) $rotas)),
                $lidas->objeto('modulos'),
            ),
        );
    }
}
