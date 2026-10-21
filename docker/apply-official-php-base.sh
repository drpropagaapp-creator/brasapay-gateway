#!/usr/bin/env sh
# Puxa a tag oficial explícita, constrói imagem candidata, valida e só então
# recria serviços PHP. Não faz down -v nem prune. Não compila PHP.
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

PHP_BASE_IMAGE="${GETFY_PHP_BASE_IMAGE:-php:8.3.36-cli-alpine}"
REQUIRED_PHP="${GETFY_PHP_REQUIRED_VERSION:-8.3.36}"

if ! command -v docker >/dev/null 2>&1; then
  echo "docker não encontrado." >&2
  exit 1
fi

echo "=== Verificação da base oficial ==="
echo "Tag: $PHP_BASE_IMAGE"
if ! docker pull "$PHP_BASE_IMAGE"; then
  echo "Base oficial $PHP_BASE_IMAGE ainda não está no Docker Hub." >&2
  echo "Stack local/produção não foi alterada." >&2
  exit 2
fi

BASE_VERSION="$(docker run --rm "$PHP_BASE_IMAGE" php -r 'echo PHP_VERSION;')"
echo "php -v na tag puxada: $BASE_VERSION"
case "$BASE_VERSION" in
  "$REQUIRED_PHP"|"$REQUIRED_PHP"*) ;;
  *)
    echo "ERRO: $PHP_BASE_IMAGE reportou PHP $BASE_VERSION; esperado $REQUIRED_PHP." >&2
    exit 1
    ;;
esac

detect_local_dev() {
  docker ps --format '{{.Names}}' 2>/dev/null | grep -qx 'stacker-gateway-app-1'
}

if detect_local_dev && [ -f docker-compose.dev.yml ] && [ -f docker-compose.local-db.yml ]; then
  PROJECT="stacker-gateway"
  APP_IMAGE="getfy_app:dev"
  COMPOSE_ARGS="-p $PROJECT -f docker-compose.local-db.yml -f docker-compose.dev.yml"
  PHP_SERVICES="app worker scheduler"
else
  if [ ! -f docker/detect-compose-files.sh ]; then
    echo "docker/detect-compose-files.sh ausente." >&2
    exit 1
  fi
  COMPOSE_FILES="$(sh docker/detect-compose-files.sh)"
  PROJECT="${GETFY_COMPOSE_PROJECT_NAME:-getfy}"
  APP_IMAGE="getfy_app:latest"
  COMPOSE_ARGS="-p $PROJECT"
  for f in $COMPOSE_FILES; do
    [ -n "$f" ] && COMPOSE_ARGS="$COMPOSE_ARGS -f $f"
  done
  if [ -f .docker/stack.env ]; then
    COMPOSE_ARGS="$COMPOSE_ARGS --env-file .docker/stack.env"
  fi
  PHP_SERVICES=""
  # shellcheck disable=SC2086
  for svc in $(docker compose $COMPOSE_ARGS config --services 2>/dev/null); do
    case "$svc" in
      app|queue|scheduler|worker|worker-*) PHP_SERVICES="$PHP_SERVICES $svc" ;;
    esac
  done
  PHP_SERVICES="$(echo "$PHP_SERVICES" | sed 's/^ //')"
fi

if [ -z "$PHP_SERVICES" ]; then
  echo "Nenhum serviço PHP encontrado para recreate." >&2
  exit 1
fi

CANDIDATE="${APP_IMAGE}-php${REQUIRED_PHP}-candidate"
ROLLBACK="${APP_IMAGE}-rollback-pre-${REQUIRED_PHP}"

echo "Projeto: $PROJECT"
echo "Imagem alvo: $APP_IMAGE"
echo "Candidata: $CANDIDATE"
echo "Rollback: $ROLLBACK"
echo "Serviços PHP: $PHP_SERVICES"

if docker image inspect "$APP_IMAGE" >/dev/null 2>&1; then
  docker tag "$APP_IMAGE" "$ROLLBACK"
  echo "Rollback tagueado: $ROLLBACK"
else
  echo "Aviso: $APP_IMAGE ainda não existe — rollback não foi criado."
fi

export GETFY_PHP_BASE_IMAGE="$PHP_BASE_IMAGE"
echo "=== Build candidata (FROM $PHP_BASE_IMAGE, --pull --no-cache) ==="
docker build --pull --no-cache \
  --build-arg PHP_BASE_IMAGE="$PHP_BASE_IMAGE" \
  -t "$CANDIDATE" \
  -f "$ROOT_DIR/Dockerfile" \
  "$ROOT_DIR"

echo "=== Validação da candidata (antes de recriar serviços) ==="
CANDIDATE_VERSION="$(docker run --rm --entrypoint php "$CANDIDATE" -r 'echo PHP_VERSION;')"
echo "Candidata PHP: $CANDIDATE_VERSION"
case "$CANDIDATE_VERSION" in
  "$REQUIRED_PHP"|"$REQUIRED_PHP"*) ;;
  *)
    echo "ERRO: candidata tem PHP $CANDIDATE_VERSION; esperado $REQUIRED_PHP. Serviços em execução não foram tocados." >&2
    exit 1
    ;;
esac

MISSING=""
for ext in bcmath exif gd intl pcntl pdo_pgsql redis zip; do
  if ! docker run --rm --entrypoint php "$CANDIDATE" -m | grep -qx "$ext"; then
    MISSING="$MISSING $ext"
  fi
done
if ! docker run --rm --entrypoint php "$CANDIDATE" -m | grep -q "Zend OPcache"; then
  MISSING="$MISSING opcache"
fi
if [ -n "$MISSING" ]; then
  echo "ERRO: extensões ausentes na candidata:$MISSING. Serviços em execução não foram tocados." >&2
  exit 1
fi

if ! docker run --rm --entrypoint composer "$CANDIDATE" check-platform-reqs --no-dev; then
  echo "ERRO: composer check-platform-reqs falhou na candidata. Serviços em execução não foram tocados." >&2
  exit 1
fi

echo "Candidata OK. Promovendo $CANDIDATE -> $APP_IMAGE"
docker tag "$CANDIDATE" "$APP_IMAGE"

echo "=== Recriando serviços PHP (volumes preservados; sem down -v / prune) ==="
# shellcheck disable=SC2086
docker compose $COMPOSE_ARGS up -d --no-deps --force-recreate $PHP_SERVICES

for svc in $PHP_SERVICES; do
  echo "--- $svc ---"
  # shellcheck disable=SC2086
  docker compose $COMPOSE_ARGS exec -T "$svc" php -r 'echo PHP_VERSION, PHP_EOL;'
done

echo "=== apply-official-php-base concluído ($REQUIRED_PHP) ==="
echo "Rollback: docker tag $ROLLBACK $APP_IMAGE && docker compose $COMPOSE_ARGS up -d --no-deps --force-recreate $PHP_SERVICES"
