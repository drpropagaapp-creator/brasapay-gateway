#!/usr/bin/env sh
# Instala vendor/ no host via container Composer (rede do host) — evita timeout do
# api.github.com durante o docker build (rede isolada do BuildKit).
set -e

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

if [ "${GETFY_SKIP_COMPOSER_INSTALL:-}" = "1" ] || [ "${GETFY_SKIP_COMPOSER_INSTALL:-}" = "true" ]; then
  echo "GETFY_SKIP_COMPOSER_INSTALL ativo — pulando composer install."
  exit 0
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "docker não encontrado — não foi possível instalar dependências PHP." >&2
  exit 1
fi

if [ ! -f composer.json ] || [ ! -f composer.lock ]; then
  echo "composer.json ou composer.lock ausente em $ROOT_DIR" >&2
  exit 1
fi

COMPOSER_IMAGE="${GETFY_COMPOSER_IMAGE:-getfy/composer-php83:local}"
COMPOSER_DOCKERFILE="$ROOT_DIR/docker/composer.Dockerfile"
PHP_BASE_IMAGE="${GETFY_PHP_BASE_IMAGE:-php:8.3.36-fpm-alpine}"

if [ -z "${GETFY_COMPOSER_IMAGE:-}" ]; then
  BUILD_OPTS="--pull --build-arg PHP_BASE_IMAGE=${PHP_BASE_IMAGE}"
  if [ "${GETFY_DOCKER_NO_CACHE:-0}" = "1" ]; then
    BUILD_OPTS="$BUILD_OPTS --no-cache"
  fi
  echo "Construindo/atualizando imagem Composer ($COMPOSER_IMAGE) a partir de $PHP_BASE_IMAGE ..."
  # shellcheck disable=SC2086
  docker build $BUILD_OPTS -f "$COMPOSER_DOCKERFILE" -t "$COMPOSER_IMAGE" "$ROOT_DIR/docker"
fi

echo "Instalando dependências PHP (composer install) com imagem $COMPOSER_IMAGE e rede do host ..."

COMPOSER_FLAGS="--no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts"

# composer:2 oficial não traz ext-gd; ignorar só se o operador forçar essa imagem.
if [ "$COMPOSER_IMAGE" = "composer:2" ] || echo "$COMPOSER_IMAGE" | grep -q '^composer:'; then
  COMPOSER_FLAGS="$COMPOSER_FLAGS --ignore-platform-req=ext-gd"
fi

docker run --rm --network host \
  -e COMPOSER_PROCESS_TIMEOUT="${GETFY_COMPOSER_PROCESS_TIMEOUT:-900}" \
  -e COMPOSER_HTTP_TIMEOUT="${GETFY_COMPOSER_HTTP_TIMEOUT:-300}" \
  -e COMPOSER_ALLOW_SUPERUSER=1 \
  -v "$ROOT_DIR:/app" \
  -w /app \
  "$COMPOSER_IMAGE" \
  composer install $COMPOSER_FLAGS

if [ ! -f vendor/autoload.php ]; then
  echo "Erro: vendor/autoload.php não foi gerado." >&2
  exit 1
fi

echo "Dependências PHP instaladas: vendor/autoload.php"
