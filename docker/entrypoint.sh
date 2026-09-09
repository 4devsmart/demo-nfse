#!/bin/sh
set -e

# Tudo que a aplicacao precisa para subir em qualquer ambiente fica aqui, e so
# aqui: quem faz `docker compose up` nao roda mais nada a mao.

preparar_diretorios() {
    mkdir -p storage/framework/cache/data \
             storage/framework/sessions \
             storage/framework/views \
             storage/logs \
             storage/app/private \
             storage/database \
             bootstrap/cache
}

preparar_env() {
    if [ "${APP_ENV}" = "production" ]; then
        [ -n "${APP_KEY}" ] || {
            echo "APP_KEY vazia. Em producao ela precisa ser fixa: gere com" >&2
            echo "  docker compose run --rm app php artisan key:generate --show" >&2
            echo "e coloque no .env. Chave nova torna ilegivel o certificado ja gravado." >&2
            exit 1
        }

        # O compose tem um default para o token, e e isso que o torna
        # perigoso: sem .env os dois lados sobem combinados e tudo funciona,
        # com uma senha publicada neste repositorio.
        case "${FISCAL_API_TOKEN}" in
            ''|troque-este-token)
                echo "FISCAL_API_TOKEN nao foi trocado. Ele e a unica coisa entre a" >&2
                echo "rede interna e a emissao de documento fiscal: gere um segredo" >&2
                echo "  openssl rand -hex 32" >&2
                echo "e coloque no .env, no mesmo valor para o app e para a fiscal-api." >&2
                exit 1
                ;;
        esac

        return 0
    fi
    [ -f .env ] || cp .env.example .env
    grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force
}

preparar_dependencias() {
    [ -d vendor ] && [ -f vendor/autoload.php ] && return 0
    composer install --no-interaction --prefer-dist
}

preparar_banco() {
    banco="${DB_DATABASE:-$(pwd)/storage/database/database.sqlite}"
    mkdir -p "$(dirname "$banco")"
    [ -f "$banco" ] || touch "$banco"
    php artisan migrate --force --no-interaction
}

# Tabela oficial e dado de referencia: sem ela nao ha como cadastrar nem emitir.
# Os municipios do IBGE dizem quem pode ser cadastrado; a lista de servicos e os
# codigos de tributacao nacional sao o que o campo obrigatorio da nota aceita, e
# sem eles o seletor abre vazio e nota nenhuma grava.
#
# Os dados de demonstracao, esses sim, so entram fora de producao.
preparar_dados() {
    php artisan db:seed --class=CidadeSeeder --force --no-interaction
    php artisan db:seed --class=ListaDeServicosSeeder --force --no-interaction
    php artisan db:seed --class=CodigoDeTributacaoNacionalSeeder --force --no-interaction
    php artisan db:seed --class=CorrelacaoNbsSeeder --force --no-interaction
    php artisan db:seed --class=ClassificacaoTributariaSeeder --force --no-interaction
    php artisan db:seed --class=IndicadorDeOperacaoSeeder --force --no-interaction
    php artisan db:seed --class=CargaTributariaSeeder --force --no-interaction

    if [ "${APP_ENV}" != "production" ]; then
        php artisan db:seed --force --no-interaction
    fi
}

preparar_cache() {
    if [ "${APP_ENV}" = "production" ]; then
        php artisan config:cache
        php artisan route:cache
        php artisan view:cache
        return 0
    fi
    php artisan optimize:clear
}

# Preparar so quando o container vai servir HTTP. Comandos avulsos
# (`docker compose run --rm app php artisan ...`) passam direto.
if [ -f artisan ] && [ "$1" = "frankenphp" ]; then
    preparar_diretorios
    preparar_dependencias
    preparar_env
    preparar_banco
    preparar_dados
    php artisan filament:assets
    php artisan storage:link --quiet 2>/dev/null || true
    preparar_cache
fi

exec "$@"
