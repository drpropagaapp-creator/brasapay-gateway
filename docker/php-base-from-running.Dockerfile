# PHP-only: nova base oficial + código da imagem em execução.
#
# Como o código em execução é preservado
# --------------------------------------
# APP_CODE_IMAGE deve ser o ID/tag da imagem do container *app* em execução
# (não getfy_app:latest, que pode apontar para outra build). O estágio
# `current` só exporta /var/www/html — artisan, app/, vendor/, VERSION.
# Nada do host (git sujo, .env, bootstrap/cache de outro ambiente) entra
# nesse COPY.
#
# Compatibilidade PHP / extensões / bibliotecas
# ---------------------------------------------
# PHP_BASE_IMAGE aqui é o estágio `php_base` do Dockerfile principal
# (apk + pecl redis + docker-php-ext-install gd pdo_pgsql zip …),
# reconstruído contra a tag oficial (ex.: php:8.3.36-cli-alpine).
# Extensões nativas (.so) e libs C ficam em /usr/local da php_base —
# NÃO são copiadas da imagem antiga (ABI da 8.3.35 quebraria na 8.3.36).
# vendor/ é PHP userland (composer.lock ^8.3); viaja com /var/www/html.
# entrypoint e ini vêm deste checkout (GETFY_SKIP_MIGRATE, sync DB_*).
# Caches de config/rotas gerados noutro ambiente são apagados no RUN.
ARG PHP_BASE_IMAGE=php:8.3.36-cli-alpine
ARG APP_CODE_IMAGE=getfy_app:latest

FROM ${APP_CODE_IMAGE} AS current

FROM ${PHP_BASE_IMAGE} AS app

WORKDIR /var/www/html

COPY --from=current /var/www/html /var/www/html
COPY docker/entrypoint.sh /usr/local/bin/getfy-entrypoint
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/99-uploads.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/90-opcache.ini

RUN chmod +x /usr/local/bin/getfy-entrypoint \
    && rm -f bootstrap/cache/config.php \
             bootstrap/cache/routes.php \
             bootstrap/cache/routes-v7.php \
             bootstrap/cache/events.php \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache .docker \
    && chmod -R 777 storage bootstrap/cache .docker

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/getfy-entrypoint"]
CMD ["sh", "-lc", "php artisan serve --host=0.0.0.0 --port=${PORT:-80} --no-reload"]
