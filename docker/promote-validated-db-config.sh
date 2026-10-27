#!/usr/bin/env sh
# Promove credenciais já validadas por TCP para a configuração principal
# (stack.env → .env → stack.compose.env) e recria só os serviços PHP
# SEM os overrides temporários (.docker/php836.override.yml etc.).
#
# Override JSON da VPS: services.app.environment + services.queue.environment
# (Compose escapa $ como $$). App e queue precisam coincidir. Parser PHP não
# imprime segredos. Restauração reanexa o overlay de banco derivado do JSON.
#
# Imagem em produção tem entrypoint antigo: GETFY_SKIP_MIGRATE é ignorado.
# A promoção força GETFY_RUN_SETUP=false no overlay de runtime (não migrate).
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
RUNTIME_OVERLAY=".docker/php-promote-runtime.yml"
DB_RESTORE_YML=".docker/php836.db.restore.yml"
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
  if [ -f "$OVERRIDE_JSON" ]; then
    cp -f "$OVERRIDE_JSON" "$BACKUP_DIR/php836.db.override.json.bak"
    chmod 600 "$BACKUP_DIR/php836.db.override.json.bak" 2>/dev/null || true
  fi
  if [ -f "$OVERRIDE_YML" ]; then
    cp -f "$OVERRIDE_YML" "$BACKUP_DIR/php836.override.yml.bak"
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

# Entrypoint antigo ignora GETFY_SKIP_MIGRATE; GETFY_RUN_SETUP=false pula migrate.
write_no_setup_overlay() {
  cat > "$RUNTIME_OVERLAY" <<'EOF'
# Gerado na promoção — não versione. Força skip de setup/migrate na imagem em produção.
services:
  app:
    environment:
      GETFY_RUN_SETUP: "false"
      GETFY_SKIP_MIGRATE: "1"
  queue:
    environment:
      GETFY_RUN_SETUP: "false"
      GETFY_SKIP_MIGRATE: "1"
EOF
}

php_db_select1() {
  svc="$1"
  args="$2"
  # shellcheck disable=SC2086
  docker compose $args exec -T "$svc" php -r '
$h = getenv("DB_HOST") ?: "postgres";
$p = getenv("DB_PORT") ?: "5432";
$d = getenv("DB_DATABASE");
$u = getenv("DB_USERNAME");
$w = getenv("DB_PASSWORD");
if (!is_string($d) || $d === "" || !is_string($u) || $u === "" || !is_string($w) || $w === "") { exit(1); }
try {
  $pdo = new PDO("pgsql:host=".$h.";port=".$p.";dbname=".$d, $u, $w, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $row = $pdo->query("SELECT 1 AS ok")->fetch(PDO::FETCH_OBJ);
  exit((isset($row->ok) && (int)$row->ok === 1) ? 0 : 1);
} catch (Throwable $e) {
  exit(1);
}
' >/dev/null 2>&1
}

wait_php_db_select1() {
  svc="$1"
  args="$2"
  i=0
  while [ "$i" -lt 40 ]; do
    php_db_select1 "$svc" "$args" && return 0
    i=$((i + 1))
    sleep 1
  done
  return 1
}

parse_vps_override() {
  outdir="$1"
  case "$outdir" in
    /*) ;;
    *) outdir="${ROOT_DIR}/${outdir}" ;;
  esac
  mkdir -p "$outdir"
  chmod 700 "$outdir" 2>/dev/null || true
  cid="$APP_CID"
  if [ -z "$cid" ]; then
    echo "[promote] ERRO: sem container app para parsear override." >&2
    return 1
  fi
  # stdin → WORKDIR (caminhos relativos). Evita docker cp/exec com /abs
  # que o Git Bash converte para C:/Users/.../AppData/Local/Temp.
  docker exec "$cid" php -r 'mkdir(".vps-parse-out", 0700, true); file_put_contents(".vps-empty", "");'
  docker exec -i "$cid" php -r 'file_put_contents(".vps-parse.php", stream_get_contents(STDIN));' \
    < "${ROOT_DIR}/docker/parse-vps-db-override.php"
  json_arg=".vps-empty"
  yml_arg=".vps-empty"
  if [ -f "$OVERRIDE_JSON" ]; then
    docker exec -i "$cid" php -r 'file_put_contents(".vps-in.json", stream_get_contents(STDIN));' \
      < "${ROOT_DIR}/${OVERRIDE_JSON}"
    json_arg=".vps-in.json"
  fi
  if [ -f "$OVERRIDE_YML" ]; then
    docker exec -i "$cid" php -r 'file_put_contents(".vps-in.yml", stream_get_contents(STDIN));' \
      < "${ROOT_DIR}/${OVERRIDE_YML}"
    yml_arg=".vps-in.yml"
  fi
  docker exec "$cid" php .vps-parse.php "$json_arg" "$yml_arg" .vps-parse-out
  rc=$?
  if [ "$rc" -eq 0 ]; then
    for f in username password database host restore.yml ok; do
      docker exec "$cid" cat ".vps-parse-out/$f" > "$outdir/$f" 2>/dev/null || true
    done
  fi
  docker exec "$cid" php -r 'foreach ([".vps-parse-out",".vps-parse.php",".vps-in.json",".vps-in.yml",".vps-empty"] as $p) { is_dir($p) ? @system("rm -rf ".$p) : @unlink($p); }' >/dev/null 2>&1 || true
  return "$rc"
}

restore_running() {
  echo "[promote] Health/recreate falhou — restaurando configuração anterior (inclui JSON de banco)." >&2
  restore_config
  unset_db_shell_env
  COMPOSE_ENV="$(sh docker/write-compose-env.sh)"
  restore_args="-p $PROJECT --env-file $COMPOSE_ENV"
  for f in $COMPOSE_FILES; do
    [ -n "$f" ] && restore_args="$restore_args -f $f"
  done
  [ -f "$IMAGE_OVERRIDE" ] && restore_args="$restore_args -f $IMAGE_OVERRIDE"
  [ -f "$OVERRIDE_YML" ] && restore_args="$restore_args -f $OVERRIDE_YML"
  if [ -f "$BACKUP_DIR/restore.yml" ]; then
    cp -f "$BACKUP_DIR/restore.yml" "$DB_RESTORE_YML"
  fi
  [ -f "$DB_RESTORE_YML" ] && restore_args="$restore_args -f $DB_RESTORE_YML"
  write_no_setup_overlay
  restore_args="$restore_args -f $RUNTIME_OVERLAY"
  echo "[promote] Recreate de recuperação (imagem pinada + override YAML + JSON de banco)." >&2
  # shellcheck disable=SC2086
  docker compose $restore_args up -d --no-deps --force-recreate $PHP_SERVICES || true
  app_ok=0
  queue_ok=0
  wait_php_db_select1 app "$restore_args" && app_ok=1
  if docker compose $restore_args ps -q queue >/dev/null 2>&1; then
    wait_php_db_select1 queue "$restore_args" && queue_ok=1
  else
    queue_ok=1
  fi
  if [ "$app_ok" -eq 1 ] && [ "$queue_ok" -eq 1 ]; then
    echo "[promote] Recuperação: app e queue autenticaram de novo (SELECT 1)." >&2
  else
    echo "[promote] Recuperação: app/queue ainda sem SELECT 1. Overrides permaneceram no disco." >&2
  fi
  echo "[promote] Overrides NÃO foram removidos." >&2
  echo "[promote] Rollback de imagem pré-8.3.36 (só se precisar voltar o PHP):" >&2
  echo "  docker tag $VPS_ROLLBACK_APP getfy_app:latest   # app" >&2
  echo "  docker tag $VPS_ROLLBACK_QUEUE getfy_app:latest # se a queue usava a mesma família" >&2
}

backup_config

# --- Pin da imagem em execução (antes de qualquer recreate) ---
PROBE_ARGS="-p $PROJECT"
for f in $COMPOSE_FILES; do
  [ -n "$f" ] && PROBE_ARGS="$PROBE_ARGS -f $f"
done
[ -f "$OVERRIDE_YML" ] && PROBE_ARGS="$PROBE_ARGS -f $OVERRIDE_YML"
[ -f "$OVERRIDE_JSON" ] && PROBE_ARGS="$PROBE_ARGS -f $OVERRIDE_JSON"
if [ -f .docker/stack.compose.env ]; then
  PROBE_ARGS="$PROBE_ARGS --env-file .docker/stack.compose.env"
elif [ -f "$ENV_FILE" ]; then
  PROBE_ARGS="$PROBE_ARGS --env-file $ENV_FILE"
fi

# shellcheck disable=SC2086
APP_CID="$(docker compose $PROBE_ARGS ps -q app 2>/dev/null | head -1 || true)"
if [ -z "$APP_CID" ] && [ -n "${GETFY_TEST_APP_CONTAINER:-}" ]; then
  APP_CID="$(docker inspect -f '{{.Id}}' "$GETFY_TEST_APP_CONTAINER" 2>/dev/null || true)"
fi
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

# Override VPS: services.app|queue.environment (JSON e/ou YAML). Sem logar valores.
if [ -f "$OVERRIDE_JSON" ]; then
  echo "[promote] Lendo override VPS (services.app|queue.environment; cifrões escapados do Compose; arquivo permanece)."
  PARSE_DIR="$BACKUP_DIR/parsed"
  set +e
  parse_vps_override "$PARSE_DIR"
  parse_rc=$?
  set -e
  if [ "$parse_rc" -eq 2 ]; then
    echo "[promote] ERRO: app e queue divergem (username/password/database). stack.env intocado." >&2
    exit 2
  fi
  if [ "$parse_rc" -ne 0 ] || [ ! -f "$PARSE_DIR/ok" ]; then
    echo "[promote] ERRO: override JSON/YML sem credenciais utilizáveis. stack.env não foi aplicado." >&2
    exit 1
  fi
  ov_user="$(cat "$PARSE_DIR/username")"
  ov_pass="$(cat "$PARSE_DIR/password")"
  ov_db="$(cat "$PARSE_DIR/database")"
  chmod 600 "$PARSE_DIR/username" "$PARSE_DIR/password" "$PARSE_DIR/database" 2>/dev/null || true
  if [ -z "$ov_user" ] || [ -z "$ov_pass" ]; then
    echo "[promote] ERRO: parser não extraiu user/senha. stack.env intocado." >&2
    exit 1
  fi
  if [ -f "$PARSE_DIR/restore.yml" ]; then
    cp -f "$PARSE_DIR/restore.yml" "$BACKUP_DIR/restore.yml"
    cp -f "$PARSE_DIR/restore.yml" "$DB_RESTORE_YML"
    chmod 600 "$BACKUP_DIR/restore.yml" "$DB_RESTORE_YML" 2>/dev/null || true
  fi
  set_kv_env "$ENV_FILE" GETFY_DB_USERNAME "$ov_user"
  set_kv_env "$ENV_FILE" GETFY_DB_PASSWORD "$ov_pass"
  if [ -n "$ov_db" ]; then
    set_kv_env "$ENV_FILE" GETFY_DB_DATABASE "$ov_db"
  fi
  unset ov_user ov_pass ov_db
  chmod 600 "$ENV_FILE" 2>/dev/null || true
  echo "[promote] Campos GETFY_DB_* atualizados em stack.env a partir do override aninhado."
fi

unset_db_shell_env
if ! sh docker/ensure-db-credentials.sh; then
  echo "[promote] ERRO: credenciais não autenticam por TCP. Restaurando stack.env. Overrides intactos." >&2
  restore_config
  exit 1
fi

COMPOSE_ENV="$(sh docker/write-compose-env.sh)"
write_no_setup_overlay
COMPOSE_ARGS="-p $PROJECT --env-file $COMPOSE_ENV"
for f in $COMPOSE_FILES; do
  [ -n "$f" ] && COMPOSE_ARGS="$COMPOSE_ARGS -f $f"
done
COMPOSE_ARGS="$COMPOSE_ARGS -f $IMAGE_OVERRIDE -f $RUNTIME_OVERLAY"

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

echo "[promote] Recriando serviços PHP sem -f de override JSON, imagem pinada, GETFY_RUN_SETUP=false: $PHP_SERVICES"
unset_db_shell_env
# GETFY_SKIP_MIGRATE no ambiente do host NÃO chega ao entrypoint antigo.
# O overlay RUNTIME_OVERLAY força GETFY_RUN_SETUP=false (único skip na imagem atual).
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

for svc in $PHP_SERVICES; do
  case "$svc" in
    app|queue)
      if ! wait_php_db_select1 "$svc" "$COMPOSE_ARGS"; then
        echo "[promote] ERRO: $svc não autenticou (SELECT 1) após recreate." >&2
        restore_running
        exit 1
      fi
      ;;
  esac
done
echo "[promote] app/queue autenticaram (SELECT 1) após recreate."

if [ "${GETFY_PROMOTE_FORCE_FAIL:-0}" = "1" ]; then
  echo "[promote] GETFY_PROMOTE_FORCE_FAIL=1 — falha forçada após recreate." >&2
  restore_running
  exit 1
fi

if [ "${GETFY_PROMOTE_SKIP_LARAVEL_HEALTH:-0}" != "1" ] && [ -f docker/post-update-healthcheck.sh ]; then
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
