#!/usr/bin/env sh
# Promove credenciais já validadas por TCP para a configuração principal
# (stack.env → .env → stack.compose.env) e recria só os serviços PHP
# SEM os overrides temporários (.docker/php836.override.yml etc.).
#
# Preserva a imagem PHP efetivamente em execução (ID do container), mesmo
# que getfy_app:latest aponte para outra build. Não faz docker compose build.
# Não retag em latest. Não altera senhas/roles. Não remove os overrides.
#
# Tags de rollback desta VPS (PHP 8.3.36 manual — não apagar):
#   getfy_app:rollback-app-pre-php836
#   getfy_app:rollback-queue-pre-php836
#
# Idempotente: pode rodar de novo; cada tentativa faz backup e, se o health
# falhar, restaura stack.env + recreate com a imagem anterior e os overrides.
#
# Uso:
#   cd /opt/getfy
#   sh docker/promote-validated-db-config.sh
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

# shellcheck disable=SC1091
. docker/unset-db-shell-env.sh
unset_db_shell_env

ENV_FILE=".docker/stack.env"
OVERRIDE_JSON="${GETFY_DB_OVERRIDE_JSON:-.docker/php836.db.override.json}"
OVERRIDE_YML="${GETFY_PHP_OVERRIDE_YML:-.docker/php836.override.yml}"
BACKUP_DIR=".docker/promote-backup"
IMAGE_OVERRIDE=".docker/running-php-image.yml"
REQUIRED_PHP="${GETFY_PHP_REQUIRED_VERSION:-8.3.36}"
KEEP_TAG="${GETFY_RUNNING_PHP_TAG:-getfy_app:running-php836}"

# Rollback pré-8.3.36 nesta VPS (imagens antigas; prune as preserva).
VPS_ROLLBACK_APP="getfy_app:rollback-app-pre-php836"
VPS_ROLLBACK_QUEUE="getfy_app:rollback-queue-pre-php836"

if [ ! -f "$ENV_FILE" ]; then
  echo "[promote] ERRO: $ENV_FILE ausente." >&2
  exit 1
fi

if [ ! -f docker/ensure-db-credentials.sh ] || [ ! -f docker/write-compose-env.sh ]; then
  echo "[promote] ERRO: scripts de credenciais ausentes neste checkout." >&2
  exit 1
fi

chmod +x docker/ensure-db-credentials.sh docker/write-compose-env.sh docker/detect-compose-files.sh docker/post-update-healthcheck.sh 2>/dev/null || true
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR" 2>/dev/null || true

read_kv() {
  file="$1"
  key="$2"
  [ -f "$file" ] || return 0
  grep -E "^[[:space:]]*${key}[[:space:]]*=" "$file" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '\r' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//;s/^"//;s/"$//;s/^'"'"'//;s/'"'"'$//' || true
}

set_kv_env() {
  GETFY_SET_KV_VAL="$3" awk -v k="$2" '
    BEGIN { v = ENVIRON["GETFY_SET_KV_VAL"] }
    $0 ~ "^[[:space:]]*" k "[[:space:]]*=" { print k "=" v; next }
    { print }
  ' "$1" > "$1.tmp" && mv "$1.tmp" "$1"
}

backup_config() {
  cp -f "$ENV_FILE" "$BACKUP_DIR/stack.env.bak"
  chmod 600 "$BACKUP_DIR/stack.env.bak" 2>/dev/null || true
  if [ ! -f "$BACKUP_DIR/stack.env.orig" ]; then
    cp -f "$ENV_FILE" "$BACKUP_DIR/stack.env.orig"
    chmod 600 "$BACKUP_DIR/stack.env.orig" 2>/dev/null || true
  fi
  if [ -f .docker/stack.compose.env ]; then
    cp -f .docker/stack.compose.env "$BACKUP_DIR/stack.compose.env.bak"
    chmod 600 "$BACKUP_DIR/stack.compose.env.bak" 2>/dev/null || true
  fi
}

restore_config() {
  if [ -f "$BACKUP_DIR/stack.env.bak" ]; then
    cp -f "$BACKUP_DIR/stack.env.bak" "$ENV_FILE"
    chmod 600 "$ENV_FILE" 2>/dev/null || true
    echo "[promote] stack.env restaurado do backup desta tentativa."
  fi
  if [ -f docker/write-compose-env.sh ]; then
    sh docker/write-compose-env.sh >/dev/null || true
  fi
}

container_image_id() {
  cid="$1"
  [ -n "$cid" ] || return 1
  docker inspect -f '{{.Image}}' "$cid" 2>/dev/null
}

container_php_version() {
  cid="$1"
  docker exec "$cid" php -r 'echo PHP_VERSION;' 2>/dev/null || true
}

COMPOSE_FILES="$(sh docker/detect-compose-files.sh)"
PROJECT="$(read_kv "$ENV_FILE" GETFY_COMPOSE_PROJECT_NAME)"
[ -n "$PROJECT" ] || PROJECT=getfy

write_running_image_override() {
  app_id="$1"
  queue_id="$2"
  {
    echo "# Gerado por promote-validated-db-config.sh — não versione."
    echo "services:"
    echo "  app:"
    echo "    image: $app_id"
    if [ -n "$queue_id" ]; then
      echo "  queue:"
      echo "    image: $queue_id"
    fi
  } > "$IMAGE_OVERRIDE"
}

restore_running() {
  echo "[promote] Health/recreate falhou — restaurando configuração anterior." >&2
  restore_config
  unset_db_shell_env
  COMPOSE_ENV="$(sh docker/write-compose-env.sh)"
  restore_args="-p $PROJECT --env-file $COMPOSE_ENV"
  for f in $COMPOSE_FILES; do
    [ -n "$f" ] && restore_args="$restore_args -f $f"
  done
  [ -f "$IMAGE_OVERRIDE" ] && restore_args="$restore_args -f $IMAGE_OVERRIDE"
  [ -f "$OVERRIDE_YML" ] && restore_args="$restore_args -f $OVERRIDE_YML"
  echo "[promote] Recreate de recuperação (imagem em execução + overrides, se existirem)." >&2
  # shellcheck disable=SC2086
  docker compose $restore_args up -d --no-deps --force-recreate $PHP_SERVICES || true
  echo "[promote] Overrides NÃO foram removidos." >&2
  echo "[promote] Rollback de imagem pré-8.3.36 (só se precisar voltar o PHP):" >&2
  echo "  docker tag $VPS_ROLLBACK_APP getfy_app:latest   # app" >&2
  echo "  docker tag $VPS_ROLLBACK_QUEUE getfy_app:latest # se a queue usava a mesma família" >&2
  echo "  docker compose -p $PROJECT -f $COMPOSE_FILES -f $OVERRIDE_YML --env-file .docker/stack.compose.env up -d --no-deps --force-recreate app queue" >&2
}

backup_config

# --- Pin da imagem em execução (antes de qualquer recreate) ---
PROBE_ARGS="-p $PROJECT"
for f in $COMPOSE_FILES; do
  [ -n "$f" ] && PROBE_ARGS="$PROBE_ARGS -f $f"
done
[ -f "$OVERRIDE_YML" ] && PROBE_ARGS="$PROBE_ARGS -f $OVERRIDE_YML"
if [ -f .docker/stack.compose.env ]; then
  PROBE_ARGS="$PROBE_ARGS --env-file .docker/stack.compose.env"
elif [ -f "$ENV_FILE" ]; then
  PROBE_ARGS="$PROBE_ARGS --env-file $ENV_FILE"
fi

# shellcheck disable=SC2086
APP_CID="$(docker compose $PROBE_ARGS ps -q app 2>/dev/null | head -1 || true)"
if [ -z "$APP_CID" ]; then
  APP_CID="$(docker ps --format '{{.Names}}' | grep -Ex 'getfy-app-1|getfy_app_1' | head -1 || true)"
  [ -n "$APP_CID" ] && APP_CID="$(docker inspect -f '{{.Id}}' "$APP_CID" 2>/dev/null || true)"
fi
if [ -z "$APP_CID" ]; then
  echo "[promote] ERRO: container app não encontrado — abortando sem alterar a stack." >&2
  exit 1
fi

RUNNING_PHP="$(container_php_version "$APP_CID")"
echo "[promote] PHP em execução no app: ${RUNNING_PHP:-desconhecido}"
case "$RUNNING_PHP" in
  "$REQUIRED_PHP"|"$REQUIRED_PHP"*) ;;
  *)
    echo "[promote] ERRO: PHP em execução não é $REQUIRED_PHP (${RUNNING_PHP:-vazio})." >&2
    echo "[promote] Recuse promover overrides sobre uma imagem diferente." >&2
    exit 1
    ;;
esac

APP_IMAGE_ID="$(container_image_id "$APP_CID")"
# shellcheck disable=SC2086
QUEUE_CID="$(docker compose $PROBE_ARGS ps -q queue 2>/dev/null | head -1 || true)"
QUEUE_IMAGE_ID=""
if [ -n "$QUEUE_CID" ]; then
  QUEUE_IMAGE_ID="$(container_image_id "$QUEUE_CID")"
fi

if [ -z "$APP_IMAGE_ID" ]; then
  echo "[promote] ERRO: não foi possível ler o ID da imagem do app em execução." >&2
  exit 1
fi

LATEST_ID="$(docker image inspect getfy_app:latest -f '{{.Id}}' 2>/dev/null || true)"
if [ -n "$LATEST_ID" ] && [ "$LATEST_ID" != "$APP_IMAGE_ID" ]; then
  echo "[promote] getfy_app:latest difere da imagem em execução — usando a em execução."
fi

docker tag "$APP_IMAGE_ID" "$KEEP_TAG"
if [ -n "$QUEUE_IMAGE_ID" ] && [ "$QUEUE_IMAGE_ID" != "$APP_IMAGE_ID" ]; then
  docker tag "$QUEUE_IMAGE_ID" "${KEEP_TAG}-queue"
  write_running_image_override "$KEEP_TAG" "${KEEP_TAG}-queue"
else
  write_running_image_override "$KEEP_TAG" "$KEEP_TAG"
fi
echo "[promote] Imagem pinada: app=$KEEP_TAG (não usa getfy_app:latest)."
echo "[promote] Rollback VPS pré-8.3.36: $VPS_ROLLBACK_APP / $VPS_ROLLBACK_QUEUE"

# Se o JSON temporário existir, copia só USER/PASSWORD para stack.env (sem logar valores).
if [ -f "$OVERRIDE_JSON" ]; then
  echo "[promote] Lendo override JSON (campos DB apenas; arquivo permanece no disco)."
  parsed="$(docker run --rm -v "$(pwd)/${OVERRIDE_JSON}:/d.json:ro" --entrypoint php "$KEEP_TAG" -r '
$j = json_decode((string) file_get_contents("/d.json"), true);
if (!is_array($j)) { fwrite(STDERR, "json invalido\n"); exit(1); }
$u = $j["GETFY_DB_USERNAME"] ?? $j["DB_USERNAME"] ?? $j["username"] ?? "";
$p = $j["GETFY_DB_PASSWORD"] ?? $j["DB_PASSWORD"] ?? $j["password"] ?? "";
$d = $j["GETFY_DB_DATABASE"] ?? $j["DB_DATABASE"] ?? $j["database"] ?? "";
if (!is_string($u) || $u === "" || !is_string($p) || $p === "") { fwrite(STDERR, "json sem user/senha\n"); exit(1); }
echo "U=".str_replace(["\n","\r"], "", $u)."\n";
echo "P=".str_replace(["\n","\r"], "", $p)."\n";
if (is_string($d) && $d !== "") { echo "D=".str_replace(["\n","\r"], "", $d)."\n"; }
')"
  ov_user="$(printf '%s\n' "$parsed" | grep '^U=' | head -1 | cut -d= -f2-)"
  ov_pass="$(printf '%s\n' "$parsed" | grep '^P=' | head -1 | cut -d= -f2-)"
  ov_db="$(printf '%s\n' "$parsed" | grep '^D=' | head -1 | cut -d= -f2- || true)"
  unset parsed
  if [ -z "$ov_user" ] || [ -z "$ov_pass" ]; then
    echo "[promote] ERRO: override JSON sem credenciais utilizáveis. stack.env não foi aplicado nesta etapa." >&2
    exit 1
  fi
  set_kv_env "$ENV_FILE" GETFY_DB_USERNAME "$ov_user"
  set_kv_env "$ENV_FILE" GETFY_DB_PASSWORD "$ov_pass"
  if [ -n "$ov_db" ]; then
    set_kv_env "$ENV_FILE" GETFY_DB_DATABASE "$ov_db"
  fi
  unset ov_user ov_pass ov_db
  chmod 600 "$ENV_FILE" 2>/dev/null || true
  echo "[promote] Campos GETFY_DB_* atualizados em stack.env a partir do override."
fi

unset_db_shell_env
if ! sh docker/ensure-db-credentials.sh; then
  echo "[promote] ERRO: credenciais não autenticam por TCP. Restaurando stack.env. Overrides intactos." >&2
  restore_config
  exit 1
fi

COMPOSE_ENV="$(sh docker/write-compose-env.sh)"
COMPOSE_ARGS="-p $PROJECT --env-file $COMPOSE_ENV"
for f in $COMPOSE_FILES; do
  [ -n "$f" ] && COMPOSE_ARGS="$COMPOSE_ARGS -f $f"
done
COMPOSE_ARGS="$COMPOSE_ARGS -f $IMAGE_OVERRIDE"

PHP_SERVICES=""
# shellcheck disable=SC2086
for svc in $(docker compose $COMPOSE_ARGS config --services 2>/dev/null); do
  case "$svc" in
    app|queue|scheduler|worker|worker-*) PHP_SERVICES="$PHP_SERVICES $svc" ;;
  esac
done
PHP_SERVICES="$(echo "$PHP_SERVICES" | sed 's/^ //')"
if [ -z "$PHP_SERVICES" ]; then
  echo "[promote] ERRO: nenhum serviço PHP no compose ativo." >&2
  restore_config
  exit 1
fi

echo "[promote] Recriando serviços PHP sem -f de override, imagem pinada 8.3.36: $PHP_SERVICES"
unset_db_shell_env
export GETFY_SKIP_MIGRATE=1
# shellcheck disable=SC2086
if ! docker compose $COMPOSE_ARGS up -d --no-deps --force-recreate $PHP_SERVICES; then
  restore_running
  exit 1
fi

sleep 3
POST_PHP="$(container_php_version "$(docker compose $COMPOSE_ARGS ps -q app 2>/dev/null | head -1)")"
case "$POST_PHP" in
  "$REQUIRED_PHP"|"$REQUIRED_PHP"*)
    echo "[promote] PHP após recreate: $POST_PHP"
    ;;
  *)
    echo "[promote] ERRO: PHP após recreate não é $REQUIRED_PHP (${POST_PHP:-vazio})." >&2
    restore_running
    exit 1
    ;;
esac

# shellcheck disable=SC2086
docker compose $COMPOSE_ARGS exec -T app php artisan optimize:clear >/dev/null 2>&1 || true

if [ -f docker/post-update-healthcheck.sh ]; then
  if ! GETFY_COMPOSE_FILES="$COMPOSE_FILES" GETFY_COMPOSE_ENV_FILE="$COMPOSE_ENV" \
    sh docker/post-update-healthcheck.sh "$COMPOSE_FILES"; then
    restore_running
    exit 1
  fi
fi

echo ""
echo "[promote] Configuração principal validada sem -f de override."
echo "Imagem PHP $REQUIRED_PHP em execução preservada ($KEEP_TAG)."
echo "Pode repetir este script com segurança; os overrides permanecem no disco."
echo "Se o site estiver correto, mova (não apague ainda) os temporários:"
echo "  mv .docker/php836.override.yml .docker/php836.override.yml.bak"
echo "  mv .docker/php836.db.override.json .docker/php836.db.override.json.bak"
echo "Não versione esses arquivos."
echo "Rollback pré-8.3.36: $VPS_ROLLBACK_APP / $VPS_ROLLBACK_QUEUE"
