#!/usr/bin/env sh
# Recuperação rápida quando o site está fora (521/522 / connection reset / timeout).
# Uso na VPS: cd /opt/getfy && sh docker/recover-stack.sh
# POSIX sh (sem arrays bash) — roda em Ubuntu/Debian com dash.
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

ENV_FILE=".docker/stack.env"
if [ ! -f "$ENV_FILE" ]; then
  echo "Erro: $ENV_FILE não encontrado. Rode install ou crie o stack.env." >&2
  exit 1
fi

echo "=== Getfy: recuperação do stack ==="
echo "Diretório: $ROOT_DIR"
echo ""

# uploads.ini como diretório quebra app/scheduler; /gateway no host é lixo de compose dentro do agente.
if [ -e docker/php/uploads.ini ] && { [ -d docker/php/uploads.ini ] || [ ! -f docker/php/uploads.ini ]; }; then
  echo "Corrigindo docker/php/uploads.ini (não era arquivo)..."
  rm -rf docker/php/uploads.ini
fi
mkdir -p docker/php
if [ ! -f docker/php/uploads.ini ]; then
  cat > docker/php/uploads.ini <<'EOF'
upload_max_filesize = 512M
post_max_size = 512M
memory_limit = 512M
max_execution_time = 300
EOF
fi
if [ -d /gateway/docker ] && [ "$ROOT_DIR" != "/gateway" ]; then
  echo "Aviso: /gateway no host (paths errados do agente) — remova manualmente se não for symlink: rm -rf /gateway" >&2
fi
echo ""

# Nunca faça `source` em stack.env: cifrões na senha são expandidos pela shell
# e o valor truncado vence o --env-file.
# shellcheck disable=SC1091
. docker/unset-db-shell-env.sh
unset_db_shell_env

read_kv() {
  file="$1"
  key="$2"
  grep -E "^[[:space:]]*${key}[[:space:]]*=" "$file" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '\r' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//;s/^"//;s/"$//;s/^'"'"'//;s/'"'"'$//' || true
}

GETFY_DB_USERNAME="$(read_kv "$ENV_FILE" GETFY_DB_USERNAME)"
GETFY_DB_DATABASE="$(read_kv "$ENV_FILE" GETFY_DB_DATABASE)"
GETFY_DB_PASSWORD="$(read_kv "$ENV_FILE" GETFY_DB_PASSWORD)"
GETFY_COMPOSE_PROJECT_NAME="$(read_kv "$ENV_FILE" GETFY_COMPOSE_PROJECT_NAME)"

COMPOSE_FILE="$(sh docker/detect-compose-files.sh 2>/dev/null || echo 'docker-compose.yml')"
PROJECT="${GETFY_COMPOSE_PROJECT_NAME:-getfy}"
if [ -f docker/write-compose-env.sh ]; then
  COMPOSE_ENV_FILE="$(sh docker/write-compose-env.sh)"
else
  COMPOSE_ENV_FILE="$ENV_FILE"
fi
ENV_ARGS="--env-file $COMPOSE_ENV_FILE"
# Uso sem array (dash em Ubuntu)
dc() {
  # shellcheck disable=SC2086
  docker compose -p "$PROJECT" -f "$COMPOSE_FILE" $ENV_ARGS "$@"
}

echo "Compose detectado: $COMPOSE_FILE (project: $PROJECT)"
echo "GETFY_DB_USERNAME=${GETFY_DB_USERNAME:-?}"
echo "GETFY_DB_DATABASE=${GETFY_DB_DATABASE:-getfy}"
echo ""

echo "=== 1) Estado dos containers ==="
dc ps -a 2>/dev/null || true
echo ""

echo "=== 2) Últimas linhas do app (procure 'Banco indisponível' ou 'role does not exist') ==="
dc logs app --tail 40 2>/dev/null || docker logs getfy-app-1 --tail 40 2>/dev/null || true
echo ""

if [ "$COMPOSE_FILE" = "docker-compose.caddy.yml" ]; then
  echo "=== 3) Logs Caddy ==="
  dc logs caddy --tail 25 2>/dev/null || true
  echo ""
fi

echo "=== 4) Teste PostgreSQL com credenciais do stack.env ==="
DB_USER="${GETFY_DB_USERNAME:-getfy}"
DB_NAME="${GETFY_DB_DATABASE:-getfy}"
PG_CONTAINER=""
for c in getfy-postgres-1 getfy_postgres_1; do
  if docker ps -a --format '{{.Names}}' | grep -qx "$c"; then
    PG_CONTAINER="$c"
    break
  fi
done
if [ -n "$PG_CONTAINER" ]; then
  if [ -n "${GETFY_DB_USERNAME:-}" ] && [ -n "${GETFY_DB_PASSWORD:-}" ] \
    && pg_host="$(docker exec "$PG_CONTAINER" hostname -i 2>/dev/null | awk '{print $1}')" \
    && [ -n "$pg_host" ] && [ "$pg_host" != "127.0.0.1" ] \
    && docker exec -e PGPASSWORD="$GETFY_DB_PASSWORD" "$PG_CONTAINER" \
         psql -h "$pg_host" -U "$DB_USER" -d "$DB_NAME" -w -c 'SELECT 1' >/dev/null 2>&1; then
    echo "OK: psql TCP/scram -h <ip-container> -U $DB_USER -d $DB_NAME"
  else
    echo "FALHOU: $ENV_FILE incompleto ou não coincide com o volume Postgres." >&2
    echo "Nota: com POSTGRES_USER custom, o role 'postgres' não existe (imagem oficial)."
    echo "Tentando login com credenciais atuais / default getfy..."
    if [ -n "${POSTGRES_USER:-}" ] || [ -n "${GETFY_DB_USERNAME:-}" ]; then
      true
    fi
    # ENV do container em execução (pode ser user/senha novos inválidos no volume)
    docker exec "$PG_CONTAINER" printenv | grep -E '^POSTGRES_' || true
  fi
else
  echo "Aviso: container postgres não encontrado." >&2
fi
echo ""

echo "=== 5) Sincronizar .env do host (Compose lê .env — deve espelhar GETFY_DB_* do stack.env) ==="
if [ -f docker/ensure-db-credentials.sh ]; then
  sh docker/ensure-db-credentials.sh || true
fi
if [ -f docker/ensure-host-dotenv.sh ]; then
  sh docker/ensure-host-dotenv.sh
else
  if [ ! -f .env ] || [ ! -s .env ]; then
    {
      echo "GETFY_DB_CONNECTION=${GETFY_DB_CONNECTION:-pgsql}"
      echo "GETFY_DB_HOST=${GETFY_DB_HOST:-postgres}"
      echo "GETFY_DB_PORT=${GETFY_DB_PORT:-5432}"
      echo "GETFY_DB_DATABASE=${GETFY_DB_DATABASE:-getfy}"
      echo "GETFY_DB_USERNAME=${GETFY_DB_USERNAME}"
      echo "GETFY_DB_PASSWORD=${GETFY_DB_PASSWORD}"
      echo "GETFY_APP_URL=${GETFY_APP_URL:-http://localhost}"
    } > .env
    echo "Criado .env a partir de $ENV_FILE"
  else
    echo ".env já existe ($(wc -c < .env | tr -d ' ') bytes)"
  fi
fi
# Espelha GETFY_DB_* do stack.env → .env (evita dual --env-file com user fantasma).
if [ -f .env ] && [ -f "$ENV_FILE" ]; then
  for var in GETFY_DB_CONNECTION GETFY_DB_HOST GETFY_DB_PORT GETFY_DB_DATABASE GETFY_DB_USERNAME GETFY_DB_PASSWORD; do
    val="$(grep -E "^[[:space:]]*${var}[[:space:]]*=" "$ENV_FILE" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '\r' || true)"
    val="$(printf '%s' "$val" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//;s/^"//;s/"$//;s/^'"'"'//;s/'"'"'$//')"
    [ -n "$val" ] || continue
    if grep -Eq "^[[:space:]]*${var}[[:space:]]*=" .env 2>/dev/null; then
      tmp="$(mktemp)"
      awk -v k="$var" -v v="$val" '
        $0 ~ "^[[:space:]]*" k "[[:space:]]*=" { print k "=" v; next }
        { print }
      ' .env > "$tmp" && mv "$tmp" .env
    else
      echo "${var}=${val}" >> .env
    fi
  done
  echo "GETFY_DB_* sincronizados stack.env → .env"
fi
echo ""

# Sem USERNAME/PASSWORD válidos, não faz sentido rebuild/recriar app.
if [ -z "${GETFY_DB_USERNAME:-}" ] || [ -z "${GETFY_DB_PASSWORD:-}" ]; then
  echo "ERRO: GETFY_DB_USERNAME ou GETFY_DB_PASSWORD vazios em $ENV_FILE." >&2
  echo "Recupere antes (comandos manuais abaixo) e rode de novo: sh docker/recover-stack.sh" >&2
  echo ""
  echo "=== Recuperação manual de credenciais Postgres ==="
  cat <<'HINT'
# 1) Listar roles reais do volume:
docker exec -u postgres getfy-postgres-1 psql -d postgres -c '\du'

# 2) Escolher o user (ex.: getfy_xxxxxxxx) e gerar senha nova:
DB_USER=getfy_XXXXXXXX   # troque pelo role listado
DB_PASS="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32)"
docker exec -u postgres getfy-postgres-1 psql -d postgres -c "ALTER USER \"${DB_USER}\" WITH PASSWORD '${DB_PASS}';"

# 3) Gravar no stack.env e .env:
grep -q '^GETFY_DB_USERNAME=' .docker/stack.env \
  && sed -i "s/^GETFY_DB_USERNAME=.*/GETFY_DB_USERNAME=${DB_USER}/" .docker/stack.env \
  || echo "GETFY_DB_USERNAME=${DB_USER}" >> .docker/stack.env
grep -q '^GETFY_DB_PASSWORD=' .docker/stack.env \
  && sed -i "s/^GETFY_DB_PASSWORD=.*/GETFY_DB_PASSWORD=${DB_PASS}/" .docker/stack.env \
  || echo "GETFY_DB_PASSWORD=${DB_PASS}" >> .docker/stack.env
grep -q '^GETFY_DB_DATABASE=' .docker/stack.env \
  || echo "GETFY_DB_DATABASE=getfy" >> .docker/stack.env

# 4) Confirmar login por TCP/scram (NÃO use psql sem -h: socket local é trust):
PG_IP="$(docker exec getfy-postgres-1 hostname -i | awk '{print $1}')"
docker exec -e PGPASSWORD="$DB_PASS" getfy-postgres-1 psql -h "$PG_IP" -U "$DB_USER" -d getfy -w -c 'SELECT 1'

# 5) Subir app de novo:
unset_db_shell_env
COMPOSE="$(sh docker/detect-compose-files.sh)"
docker compose -f "$COMPOSE" --env-file .docker/stack.env up -d --force-recreate app
HINT
  exit 1
fi

GETFY_PHP_BASE_IMAGE="${GETFY_PHP_BASE_IMAGE:-php:8.3.36-cli-alpine}"
export GETFY_PHP_BASE_IMAGE

echo "=== 6) Rebuild app (limites PHP na imagem) + subir stack ==="
# Libera portas de serviços removidos do compose atual (ex.: caddy órfão vs app:80).
# Não remove volumes nem serviços presentes no compose (Caddy válido permanece).
if [ -f docker/remove-stale-compose-orphans.sh ]; then
  chmod +x docker/remove-stale-compose-orphans.sh 2>/dev/null || true
  sh docker/remove-stale-compose-orphans.sh "$ENV_FILE" "$COMPOSE_FILE" || true
fi
dc build --pull app
# queue só existe em compose caddy/no-redis; no standard use app + workers.
if dc config --services 2>/dev/null | grep -qx queue; then
  dc up -d --force-recreate --no-deps app queue
else
  dc up -d --force-recreate --no-deps app
fi
echo ""

echo "Aguardando app (health /up, até 3 min)..."
APP_OK=0
for i in $(seq 1 90); do
  if dc exec -T app php -r "exit(@file_get_contents('http://127.0.0.1/up')===false?1:0);" 2>/dev/null; then
    APP_OK=1
    break
  fi
  sleep 2
done
if [ "$APP_OK" -ne 1 ]; then
  echo "App não respondeu em /up — logs:" >&2
  dc logs app --tail 40 2>/dev/null || true
  exit 1
fi

echo "=== Consulta PostgreSQL via app (SELECT 1; /up não basta) ==="
if dc exec -T app php -r '
$h = getenv("DB_HOST"); $p = getenv("DB_PORT") ?: "5432";
$d = getenv("DB_DATABASE"); $u = getenv("DB_USERNAME"); $w = getenv("DB_PASSWORD");
if (!is_string($u) || $u === "" || !is_string($w) || $w === "") { exit(1); }
try {
  $pdo = new PDO("pgsql:host=".$h.";port=".$p.";dbname=".$d, $u, $w, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $pdo->query("SELECT 1");
} catch (Throwable $e) { exit(1); }
' >/dev/null 2>&1; then
  echo "OK: app autenticou no PostgreSQL"
else
  echo "ERRO: app não autenticou no PostgreSQL após recreate. /up verde não garante o site." >&2
  dc logs app --tail 40 2>/dev/null || true
  exit 1
fi

if [ "$COMPOSE_FILE" = "docker-compose.caddy.yml" ]; then
  echo "Recriando Caddy..."
  dc up -d --force-recreate --no-deps caddy
fi
dc up -d --remove-orphans
echo ""

echo "=== Caches Laravel (optimize:clear) ==="
dc exec -T app php artisan optimize:clear || true
echo ""

echo "Aguardando estabilização (5s)..."
sleep 5
echo ""

echo "=== 7) Teste HTTP local ==="
if curl -sI --max-time 8 http://127.0.0.1/ 2>/dev/null | head -5; then
  echo ""
  echo "HTTP no servidor OK. Se o browser ainda mostra 521/522, o problema é Cloudflare → IP/porta do VPS."
else
  echo "HTTP local ainda falhou." >&2
  echo "Logs app:" >&2
  dc logs app --tail 30 2>/dev/null || true
  exit 1
fi

echo ""
echo "=== Recuperação concluída ==="
echo "Se precisar atualizar código: bash -c \"\$(curl -fsSL https://raw.githubusercontent.com/stacker-builders/stacker-gateway/main/update.sh)\""
