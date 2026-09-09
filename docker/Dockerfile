# syntax=docker/dockerfile:1

# Uma imagem so, tres estagios. FrankenPHP serve o Laravel direto de public/,
# sem nginx nem php-fpm ao lado: um processo, um container.
FROM dunglas/frankenphp:1-php8.4 AS base

ARG USER_ID=1000
ARG GROUP_ID=1000

ENV SERVER_NAME=":8080" \
    SERVER_ROOT="public/" \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1 \
    OPENSSL_CONF=/etc/ssl/openssl-legacy.cnf

WORKDIR /app

RUN install-php-extensions intl zip bcmath pcntl opcache \
 && apt-get update \
 && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# O usuario da aplicacao espelha o UID do host para que o bind mount de
# desenvolvimento nao devolva arquivos de root.
RUN groupadd --gid "${GROUP_ID}" app 2>/dev/null || true \
 && useradd --uid "${USER_ID}" --gid "${GROUP_ID}" --create-home --shell /bin/bash app 2>/dev/null || true \
 && mkdir -p /data/caddy /config/caddy /app \
 && chown -R "${USER_ID}:${GROUP_ID}" /data /config /app \
 # A imagem oficial marca o binario com CAP_NET_BIND_SERVICE para que ele possa
 # abrir as portas 80 e 443. Aqui ele escuta em :8080, que nao e privilegiada,
 # entao a capability so aumenta a superficie exposta. E o que a documentacao
 # recomenda para o caminho nao-privilegiado.
 && setcap -r /usr/local/bin/frankenphp

# Certificados A1 emitidos ha alguns anos usam RC2/3DES, que o OpenSSL 3 moveu
# para o provider `legacy` e desativou por padrao. Sem isto `openssl_pkcs12_read`
# recusa .pfx perfeitamente validos. Derivamos do openssl.cnf da distro em vez de
# substitui-lo: as demais secoes ([req], [ca], ...) continuam valendo.
RUN sed -e 's/^\[default_sect\]/[default_sect]\nactivate = 1/' \
        -e 's/^default = default_sect/default = default_sect\nlegacy = legacy_sect/' \
        /etc/ssl/openssl.cnf > /etc/ssl/openssl-legacy.cnf \
 && printf '\n[legacy_sect]\nactivate = 1\n' >> /etc/ssl/openssl-legacy.cnf

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]


# --------------------------------------------------------------- desenvolvimento
# O codigo vem por bind mount; o entrypoint instala as dependencias na subida.
FROM base AS dev

# pcov e o driver de cobertura do Infection. Fica so aqui: producao nao mede
# cobertura, e o Xdebug custaria uma ordem de grandeza a mais no mesmo trabalho.
RUN install-php-extensions pcov

# A imagem nao traz php.ini nenhum: sem isto o PHP roda com os defaults
# compilados, e `log_errors` entre eles nasce Off. O nosso zz-app.ini entra
# depois e sobrescreve o que precisa divergir.
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

COPY docker/php.dev.ini /usr/local/etc/php/conf.d/zz-app.ini

USER app


# --------------------------------------------------------------- producao
FROM base AS prod

# Idem: sem php.ini, `display_errors` nasce On e `log_errors` Off, que e o pior
# par possivel em producao.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.prod.ini /usr/local/etc/php/conf.d/zz-app.ini

COPY --chown=app:app composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY --chown=app:app . .
RUN composer dump-autoload --optimize --classmap-authoritative \
 && php artisan package:discover --ansi \
 && php artisan filament:assets \
 && chown -R app:app /app

USER app
