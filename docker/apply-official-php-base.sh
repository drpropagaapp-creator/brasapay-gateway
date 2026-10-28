#!/usr/bin/env sh
# Puxa a tag oficial explícita, constrói imagem candidata, valida e só então
# recria serviços PHP. Não faz down -v nem prune. Não compila PHP.
# PHP-only: GETFY_SKIP_MIGRATE=1; código da imagem do container em execução
# (não getfy_app:latest, que pode divergir).
#
# Tags de rollback desta VPS (não apagar; prune as preserva):
#   getfy_app:rollback-app-pre-php836
#   getfy_app:rollback-queue-pre-php836
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

PHP_BASE_IMAGE="${GETFY_PHP_BASE_IMAGE:-php:8.3.36-fpm-alpine}"
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

# shellcheck disable=SC1091
. docker/unset-db-shell-env.sh
unset_db_shell_env

if [ -f docker/ensure-db-credentials.sh ] && [ -f .docker/stack.env ]; then
  chmod +x docker/ensure-db-credentials.sh 2>/dev/null || true
  echo "=== Credenciais PostgreSQL (TCP) antes de recriar ==="
  if ! sh docker/ensure-db-credentials.sh; then
    echo "ERRO: credenciais inválidas por TCP — abortando antes de recriar PHP." >&2
    exit 1
  fi
fi

COMPOSE_ENV_FILE=""
if [ -f docker/write-compose-env.sh ] && [ -f .docker/stack.env ]; then
  COMPOSE_ENV_FILE="$(sh docker/write-compose-env.sh)"
fi

detect_local_dev() {
  docker ps --format '{{.Names}}' 2>/dev/null | grep -qx 'stacker-gateway-app-1'
}

HOST_HAS_TOKEN=0
if [ -f .env ] && grep -Eq '^[[:space:]]*STACKER_AGENT_TOKEN[[:space:]]*=.+' .env 2>/dev/null; then
  HOST_HAS_TOKEN=1
fi
if [ -f .docker/stack.env ] && grep -Eq '^[[:space:]]*STACKER_AGENT_TOKEN[[:space:]]*=.+' .docker/stack.env 2>/dev/null; then
  HOST_HAS_TOKEN=1
fi

if detect_local_dev && [ -f docker-compose.dev.yml ] && [ -f docker-compose.local-db.yml ]; then
  PROJECT="stacker-gateway"
  APP_IMAGE="getfy_app:dev"
  COMPOSE_ARGS="-p $PROJECT -f docker-compose.local-db.yml -f docker-compose.dev.yml"
  PHP_SERVICES="app worker scheduler"
  LOCAL_DEV=1
else
  LOCAL_DEV=0
  if [ ! -f docker/detect-compose-files.sh ]; then
    echo "docker/detect-compose-files.sh ausente." >&2
    exit 1
  fi
  COMPOSE_FILES="$(sh docker/detect-compose-files.sh)"
  PROJECT="${GETFY_COMPOSE_PROJECT_NAME:-getfy}"
  if [ -f .docker/stack.env ]; then
    _pn="$(grep -E '^[[:space:]]*GETFY_COMPOSE_PROJECT_NAME[[:space:]]*=' .docker/stack.env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '\r' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//;s/^"//;s/"$//' || true)"
    [ -n "$_pn" ] && PROJECT="$_pn"
  fi
  APP_IMAGE="getfy_app:latest"
  COMPOSE_ARGS="-p $PROJECT"
  for f in $COMPOSE_FILES; do
    [ -n "$f" ] && COMPOSE_ARGS="$COMPOSE_ARGS -f $f"
  done
  if [ -n "$COMPOSE_ENV_FILE" ]; then
    COMPOSE_ARGS="$COMPOSE_ARGS --env-file $COMPOSE_ENV_FILE"
  elif [ -f .docker/stack.env ]; then
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

runtime_version() {
  # shellcheck disable=SC2086
  docker compose $COMPOSE_ARGS exec -T app php -r 'echo trim(@file_get_contents("VERSION") ?: "");' 2>/dev/null || true
}

host_version() {
  if [ -f VERSION ]; then
    tr -d ' \n\r' < VERSION
  fi
}

FROM_RUNNING="${GETFY_PHP_FROM_RUNNING:-}"
if [ -z "$FROM_RUNNING" ]; then
  if [ "$LOCAL_DEV" = "1" ]; then
    FROM_RUNNING=0
  elif docker image inspect "$APP_IMAGE" >/dev/null 2>&1; then
    FROM_RUNNING=1
  else
    FROM_RUNNING=0
  fi
fi

if [ "$FROM_RUNNING" = "1" ] && ! docker image inspect "$APP_IMAGE" >/dev/null 2>&1; then
  echo "GETFY_PHP_FROM_RUNNING=1 mas $APP_IMAGE não existe — caindo para build do host." >&2
  FROM_RUNNING=0
fi

if [ "$FROM_RUNNING" != "1" ]; then
  RV="$(runtime_version | tr -d ' \n\r')"
  HV="$(host_version)"
  if [ -n "$RV" ] && [ -n "$HV" ] && [ "$RV" != "$HV" ]; then
    echo "ERRO: VERSION no host ($HV) difere da release em execução ($RV)." >&2
    echo "Recusar rebuild a partir do código do host (instalação gerenciada)." >&2
    echo "Use GETFY_PHP_FROM_RUNNING=1 para atualizar só a base PHP da imagem atual." >&2
    exit 1
  fi
  if [ "$LOCAL_DEV" != "1" ] && [ "$HOST_HAS_TOKEN" = "1" ] && [ "${GETFY_ALLOW_HOST_CODE_REBUILD:-0}" != "1" ]; then
    echo "ERRO: instalação com STACKER_AGENT_TOKEN — não reconstruir a partir do host." >&2
    echo "Use GETFY_PHP_FROM_RUNNING=1 (padrão quando a imagem existe) ou GETFY_ALLOW_HOST_CODE_REBUILD=1." >&2
    exit 1
  fi
fi

export GETFY_PHP_BASE_IMAGE="$PHP_BASE_IMAGE"
export GETFY_SKIP_MIGRATE=1

if [ "$FROM_RUNNING" = "1" ]; then
  CODE_IMAGE="$ROLLBACK"
  # shellcheck disable=SC2086
  running_cid="$(docker compose $COMPOSE_ARGS ps -q app 2>/dev/null | head -1 || true)"
  if [ -n "$running_cid" ]; then
    running_id="$(docker inspect -f '{{.Image}}' "$running_cid")"
    docker tag "$running_id" getfy_app:code-from-running
    CODE_IMAGE="getfy_app:code-from-running"
    echo "Código da candidata: imagem do container app em execução (não $APP_IMAGE)."
  fi
  echo "=== Build php_base ($PHP_BASE_IMAGE) + código de $CODE_IMAGE ==="
  PHP_BASE_TAG="getfy_php_base:${REQUIRED_PHP}"
  docker build --pull --no-cache \
    --target php_base \
    --build-arg PHP_BASE_IMAGE="$PHP_BASE_IMAGE" \
    -t "$PHP_BASE_TAG" \
    -f "$ROOT_DIR/Dockerfile" \
    "$ROOT_DIR"
  docker build --no-cache \
    --build-arg PHP_BASE_IMAGE="$PHP_BASE_TAG" \
    --build-arg APP_CODE_IMAGE="$CODE_IMAGE" \
    -t "$CANDIDATE" \
    -f "$ROOT_DIR/docker/php-base-from-running.Dockerfile" \
    "$ROOT_DIR"
else
  echo "=== Build candidata a partir do host (FROM $PHP_BASE_IMAGE, --pull --no-cache) ==="
  docker build --pull --no-cache \
    --build-arg PHP_BASE_IMAGE="$PHP_BASE_IMAGE" \
    -t "$CANDIDATE" \
    -f "$ROOT_DIR/Dockerfile" \
    "$ROOT_DIR"
fi

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

if docker run --rm --entrypoint test "$CANDIDATE" -f /var/www/html/bootstrap/cache/config.php; then
  echo "ERRO: candidata contém bootstrap/cache/config.php (cache de outro ambiente)." >&2
  exit 1
fi

if ! docker run --rm --entrypoint composer "$CANDIDATE" check-platform-reqs --no-dev; then
  echo "ERRO: composer check-platform-reqs falhou na candidata. Serviços em execução não foram tocados." >&2
  exit 1
fi

echo "Candidata OK. Promovendo $CANDIDATE -> $APP_IMAGE"
docker tag "$CANDIDATE" "$APP_IMAGE"

echo "=== Recriando serviços PHP (volumes preservados; sem down -v / prune; sem migrate) ==="
# shellcheck disable=SC2086
docker compose $COMPOSE_ARGS up -d --no-deps --force-recreate $PHP_SERVICES

for svc in $PHP_SERVICES; do
  echo "--- $svc ---"
  # shellcheck disable=SC2086
  docker compose $COMPOSE_ARGS exec -T "$svc" php -r 'echo PHP_VERSION, PHP_EOL;'
done

# shellcheck disable=SC2086
docker compose $COMPOSE_ARGS exec -T app php artisan optimize:clear >/dev/null 2>&1 || true

if [ -f docker/post-update-healthcheck.sh ] && [ "$LOCAL_DEV" != "1" ]; then
  echo "=== Health check (inclui SELECT 1) ==="
  chmod +x docker/post-update-healthcheck.sh 2>/dev/null || true
  if ! GETFY_COMPOSE_FILES="${COMPOSE_FILES:-}" GETFY_COMPOSE_ENV_FILE="$COMPOSE_ENV_FILE" \
    sh docker/post-update-healthcheck.sh "${COMPOSE_FILES:-}"; then
    echo "Health falhou. Rollback:" >&2
    echo "  docker tag $ROLLBACK $APP_IMAGE && docker compose $COMPOSE_ARGS up -d --no-deps --force-recreate $PHP_SERVICES" >&2
    exit 1
  fi
fi

echo "=== apply-official-php-base concluído ($REQUIRED_PHP) ==="
echo "Rollback: docker tag $ROLLBACK $APP_IMAGE && docker compose $COMPOSE_ARGS up -d --no-deps --force-recreate $PHP_SERVICES"
echo "Prune NÃO foi executado — tags de rollback preservadas."
