#!/usr/bin/env sh
# Testes de comportamento: promoção e recuperação no formato real do override da VPS
# (services.app.environment / services.queue.environment, $$ do Compose).
# Ambiente isolado — não usa o projeto getfy nem volumes da instalação.
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
IMAGE="getfy_promote_test:old-entrypoint"
PASS=0
FAIL=0
PROJECT=""
TEST_ROOT=""
KEEP_TAG=""

cleanup() {
  if [ -n "${PROJECT:-}" ] && [ -n "${TEST_ROOT:-}" ] && [ -d "$TEST_ROOT" ]; then
    (
      cd "$TEST_ROOT"
      docker compose -p "$PROJECT" --env-file .docker/stack.compose.env \
        -f docker-compose.yml down -v --remove-orphans >/dev/null 2>&1 || true
    )
  fi
  if [ -n "${KEEP_TAG:-}" ]; then
    docker rmi "$KEEP_TAG" >/dev/null 2>&1 || true
    docker rmi "${KEEP_TAG}-queue" >/dev/null 2>&1 || true
  fi
  if [ -n "${TEST_ROOT:-}" ]; then
    rm -rf "$TEST_ROOT"
  fi
}
trap cleanup EXIT INT TERM

assert_eq() {
  label="$1"
  got="$2"
  want="$3"
  if [ "$got" = "$want" ]; then
    echo "  PASS: $label"
    PASS=$((PASS + 1))
  else
    echo "  FAIL: $label (got='$got' want='$want')" >&2
    FAIL=$((FAIL + 1))
  fi
}

assert_true() {
  label="$1"
  if [ "$2" = "1" ]; then
    echo "  PASS: $label"
    PASS=$((PASS + 1))
  else
    echo "  FAIL: $label" >&2
    FAIL=$((FAIL + 1))
  fi
}

secret_eq() {
  label="$1"
  got="$2"
  want="$3"
  if [ "$got" = "$want" ]; then
    echo "  PASS: $label"
    PASS=$((PASS + 1))
  else
    echo "  FAIL: $label (valores diferem; não exibidos)" >&2
    FAIL=$((FAIL + 1))
  fi
}

php_select1() {
  svc="$1"
  extra="${2:-}"
  # shellcheck disable=SC2086
  docker compose -p "$PROJECT" --env-file .docker/stack.compose.env \
    -f docker-compose.yml $extra \
    exec -T "$svc" php -r '
$h = getenv("DB_HOST") ?: "postgres";
$p = getenv("DB_PORT") ?: "5432";
$d = getenv("DB_DATABASE"); $u = getenv("DB_USERNAME"); $w = getenv("DB_PASSWORD");
if (!is_string($d) || $d === "" || !is_string($u) || $u === "" || !is_string($w) || $w === "") { exit(1); }
try {
  $pdo = new PDO("pgsql:host=".$h.";port=".$p.";dbname=".$d, $u, $w, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $row = $pdo->query("SELECT 1 AS ok")->fetch(PDO::FETCH_OBJ);
  exit((isset($row->ok) && (int)$row->ok === 1) ? 0 : 1);
} catch (Throwable $e) { exit(1); }
' >/dev/null 2>&1
}

wait_select1() {
  svc="$1"
  extra="${2:-}"
  i=0
  while [ "$i" -lt 40 ]; do
    php_select1 "$svc" "$extra" && return 0
    i=$((i + 1))
    sleep 1
  done
  return 1
}

printenv_trim() {
  svc="$1"
  key="$2"
  extra="${3:-}"
  # shellcheck disable=SC2086
  docker compose -p "$PROJECT" --env-file .docker/stack.compose.env \
    -f docker-compose.yml $extra \
    exec -T "$svc" printenv "$key" | tr -d '\r\n'
}

svc_ok() {
  extra="${1:-}"
  set +e
  wait_select1 app "$extra"
  a=$?
  wait_select1 queue "$extra"
  q=$?
  set -e
  [ "$a" -eq 0 ] && [ "$q" -eq 0 ]
}

new_project() {
  PROJECT="pmt$$$(awk 'BEGIN{srand(); printf "%04d", int(rand()*10000)}')"
  KEEP_TAG="getfy_promote_test:running-${PROJECT}"
  # Diretório no checkout (Windows/Docker Desktop monta C:). Não usar /tmp do Git Bash.
  TEST_ROOT="$ROOT_DIR/tmp-promote-${PROJECT}"
  rm -rf "$TEST_ROOT"
  mkdir -p "$TEST_ROOT/docker" "$TEST_ROOT/.docker"
  cp "$ROOT_DIR/docker/promote-validated-db-config.sh" "$TEST_ROOT/docker/"
  cp "$ROOT_DIR/docker/parse-vps-db-override.php" "$TEST_ROOT/docker/"
  cp "$ROOT_DIR/docker/ensure-db-credentials.sh" "$TEST_ROOT/docker/"
  cp "$ROOT_DIR/docker/write-compose-env.sh" "$TEST_ROOT/docker/"
  cp "$ROOT_DIR/docker/unset-db-shell-env.sh" "$TEST_ROOT/docker/"
  cp "$ROOT_DIR/docker/detect-compose-files.sh" "$TEST_ROOT/docker/"
  cp "$ROOT_DIR/docker/post-update-healthcheck.sh" "$TEST_ROOT/docker/"
  cp "$ROOT_DIR/docker/tests/fixtures/promote-vps/docker-compose.yml" "$TEST_ROOT/docker-compose.yml"
  cp "$ROOT_DIR/docker/tests/fixtures/promote-vps/php836.override.yml" "$TEST_ROOT/.docker/php836.override.yml"
  cp "$ROOT_DIR/docker/tests/fixtures/promote-vps/php836.db.override.json" "$TEST_ROOT/.docker/php836.db.override.json"
  printf '%s\n' "standard" > "$TEST_ROOT/.docker/compose-profile"
  cat > "$TEST_ROOT/.docker/stack.env" <<EOF
GETFY_COMPOSE_PROJECT_NAME=${PROJECT}
GETFY_COMPOSE_FILES=docker-compose.yml
GETFY_DB_CONNECTION=pgsql
GETFY_DB_HOST=postgres
GETFY_DB_PORT=5432
GETFY_DB_DATABASE=getfy
GETFY_DB_USERNAME=getfy_prod
GETFY_DB_PASSWORD=CorrectPass!
EOF
  chmod 600 "$TEST_ROOT/.docker/stack.env"
  (
    cd "$TEST_ROOT"
    sh docker/write-compose-env.sh >/dev/null
  )
}

start_stack_with_overrides() {
  (
    cd "$TEST_ROOT"
    docker compose -p "$PROJECT" --env-file .docker/stack.compose.env \
      -f docker-compose.yml \
      -f .docker/php836.override.yml \
      -f .docker/php836.db.override.json \
      up -d
  )
}

dc_extra() {
  printf '%s' "-f .docker/php836.override.yml -f .docker/php836.db.override.json"
}

promote_env() {
  GETFY_RUNNING_PHP_TAG="$KEEP_TAG"
  export GETFY_RUNNING_PHP_TAG
  export GETFY_PHP_REQUIRED_VERSION=8.3.36
  export GETFY_PROMOTE_SKIP_LARAVEL_HEALTH=1
  export GETFY_COMPOSE_FILES=docker-compose.yml
  export GETFY_TEST_SKIP_VOLUMES=1
  export GETFY_TEST_PG_CONTAINER
  export GETFY_TEST_APP_CONTAINER
  GETFY_TEST_PG_CONTAINER="$(
    cd "$TEST_ROOT"
    docker compose -p "$PROJECT" --env-file .docker/stack.compose.env -f docker-compose.yml \
      ps --format '{{.Name}}' postgres | head -1
  )"
  GETFY_TEST_APP_CONTAINER="$(
    cd "$TEST_ROOT"
    docker compose -p "$PROJECT" --env-file .docker/stack.compose.env -f docker-compose.yml \
      ps --format '{{.Name}}' app | head -1
  )"
  export GETFY_TEST_PG_CONTAINER GETFY_TEST_APP_CONTAINER
}

down_stack() {
  if [ -n "${PROJECT:-}" ] && [ -n "${TEST_ROOT:-}" ] && [ -d "$TEST_ROOT" ]; then
    (
      cd "$TEST_ROOT"
      docker compose -p "$PROJECT" --env-file .docker/stack.compose.env \
        -f docker-compose.yml down -v --remove-orphans >/dev/null 2>&1 || true
    )
  fi
  if [ -n "${KEEP_TAG:-}" ]; then
    docker rmi "$KEEP_TAG" >/dev/null 2>&1 || true
    docker rmi "${KEEP_TAG}-queue" >/dev/null 2>&1 || true
  fi
  if [ -n "${TEST_ROOT:-}" ]; then
    rm -rf "$TEST_ROOT"
  fi
  PROJECT=""
  TEST_ROOT=""
  KEEP_TAG=""
}

if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
  echo "ERRO: docker compose é obrigatório para estes testes." >&2
  exit 1
fi

echo ""
echo "=== build imagem entrypoint antigo ==="
docker build -t "$IMAGE" "$ROOT_DIR/docker/tests/fixtures/old-entrypoint"

echo ""
echo "=== 1) entrypoint antigo ignora GETFY_SKIP_MIGRATE ==="
set +e
docker run --rm --name "pmt-old-skip-$$" \
  -e GETFY_RUN_SETUP=true \
  -e GETFY_SKIP_MIGRATE=1 \
  "$IMAGE" sh -c 'test -f /tmp/migrate-ran'
skip_ignored=$?
docker run --rm --name "pmt-old-nosetup-$$" \
  -e GETFY_RUN_SETUP=false \
  -e GETFY_SKIP_MIGRATE=0 \
  "$IMAGE" sh -c 'test ! -f /tmp/migrate-ran'
nosetup_ok=$?
set -e
assert_eq "1: GETFY_SKIP_MIGRATE=1 ainda migra (RUN_SETUP=true)" "$skip_ignored" "0"
assert_eq "1: GETFY_RUN_SETUP=false não migra" "$nosetup_ok" "0"

echo ""
echo '=== 2) promoção com override aninhado da VPS ($$ -> $, app=queue) ==='
new_project
start_stack_with_overrides
promote_env
ok_before=0
(
  cd "$TEST_ROOT"
  svc_ok "$(dc_extra)"
) && ok_before=1
assert_true "2: app/queue autenticam COM override JSON antes da promoção" "$ok_before"

got_pass="$(
  cd "$TEST_ROOT"
  printenv_trim app DB_PASSWORD "$(dc_extra)"
)"
secret_eq '2: container recebeu senha com cifrão (JSON Compose $$)' "$got_pass" 'Correct$Pass!'

set +e
promote_out="$(
  cd "$TEST_ROOT"
  promote_env
  GETFY_PROMOTE_FORCE_FAIL=0 sh docker/promote-validated-db-config.sh 2>&1
)"
promote_rc=$?
set -e
assert_eq "2: promote exit 0" "$promote_rc" "0"
if [ "$promote_rc" -ne 0 ]; then
  echo "$promote_out" | tail -40 >&2
fi
leaked=0
echo "$promote_out" | grep -F 'Correct$' >/dev/null && leaked=1 || true
echo "$promote_out" | grep -F 'CorrectPass' >/dev/null && leaked=1 || true
assert_eq "2: promote não imprime credenciais" "$leaked" "0"

canon="$(grep '^GETFY_DB_PASSWORD=' "$TEST_ROOT/.docker/stack.env" | cut -d= -f2- | tr -d '\r')"
secret_eq "2: stack.env canônico tem a senha com cifrão" "$canon" 'Correct$Pass!'
compose_esc="$(grep '^GETFY_DB_PASSWORD=' "$TEST_ROOT/.docker/stack.compose.env" | cut -d= -f2- | tr -d '\r')"
secret_eq "2: stack.compose.env re-escapa cifrão" "$compose_esc" 'Correct$$Pass!'

ok_after=0
(
  cd "$TEST_ROOT"
  svc_ok
) && ok_after=1
assert_true "2: app/queue autenticam SEM -f JSON após promoção" "$ok_after"

migrated_app="$(
  cd "$TEST_ROOT"
  docker compose -p "$PROJECT" --env-file .docker/stack.compose.env -f docker-compose.yml \
    exec -T app sh -c 'test -f /tmp/migrate-ran && echo 1 || echo 0' | tr -d '\r\n'
)"
migrated_queue="$(
  cd "$TEST_ROOT"
  docker compose -p "$PROJECT" --env-file .docker/stack.compose.env -f docker-compose.yml \
    exec -T queue sh -c 'test -f /tmp/migrate-ran && echo 1 || echo 0' | tr -d '\r\n'
)"
assert_eq "2: app não rodou migrate (entrypoint antigo + RUN_SETUP=false)" "$migrated_app" "0"
assert_eq "2: queue não rodou migrate" "$migrated_queue" "0"

setup_app="$(
  cd "$TEST_ROOT"
  printenv_trim app GETFY_RUN_SETUP
)"
assert_eq "2: overlay forçou GETFY_RUN_SETUP=false no app" "$setup_app" "false"

down_stack

echo ""
echo "=== 3) restore_running após falha forçada reanexa JSON de banco ==="
new_project
start_stack_with_overrides
promote_env
set +e
restore_out="$(
  cd "$TEST_ROOT"
  promote_env
  GETFY_PROMOTE_FORCE_FAIL=1 sh docker/promote-validated-db-config.sh 2>&1
)"
restore_rc=$?
set -e
forced_fail=0
[ "$restore_rc" -ne 0 ] && forced_fail=1
assert_true "3: promote sai diferente de 0 na falha forçada" "$forced_fail"
echo "$restore_out" | grep -q 'GETFY_PROMOTE_FORCE_FAIL' && ff=1 || ff=0
assert_true "3: log registra falha forçada após recreate" "$ff"
echo "$restore_out" | grep -q 'autenticaram de novo' && rec=1 || rec=0
assert_true "3: log registra recuperação app/queue" "$rec"
rleak=0
echo "$restore_out" | grep -F 'Correct$' >/dev/null && rleak=1 || true
assert_eq "3: recuperação não imprime senha" "$rleak" "0"

canon_restored="$(grep '^GETFY_DB_PASSWORD=' "$TEST_ROOT/.docker/stack.env" | cut -d= -f2- | tr -d '\r')"
secret_eq "3: stack.env voltou ao backup truncado" "$canon_restored" 'CorrectPass!'

restore_extra=""
[ -f "$TEST_ROOT/.docker/php836.override.yml" ] && restore_extra="$restore_extra -f .docker/php836.override.yml"
[ -f "$TEST_ROOT/.docker/php836.db.restore.yml" ] && restore_extra="$restore_extra -f .docker/php836.db.restore.yml"
[ -f "$TEST_ROOT/.docker/php-promote-runtime.yml" ] && restore_extra="$restore_extra -f .docker/php-promote-runtime.yml"
[ -f "$TEST_ROOT/.docker/running-php-image.yml" ] && restore_extra="$restore_extra -f .docker/running-php-image.yml"

ok_restore=0
(
  cd "$TEST_ROOT"
  svc_ok "$restore_extra"
) && ok_restore=1
assert_true "3: app/queue autenticam de novo após restore (SELECT 1)" "$ok_restore"

got_restored="$(
  cd "$TEST_ROOT"
  printenv_trim app DB_PASSWORD "$restore_extra"
)"
secret_eq "3: overlay JSON restaurou a senha com cifrão no app" "$got_restored" 'Correct$Pass!'

got_q="$(
  cd "$TEST_ROOT"
  printenv_trim queue DB_PASSWORD "$restore_extra"
)"
secret_eq "3: overlay JSON restaurou a senha com cifrão na queue" "$got_q" 'Correct$Pass!'

migrated_after="$(
  cd "$TEST_ROOT"
  # shellcheck disable=SC2086
  docker compose -p "$PROJECT" --env-file .docker/stack.compose.env -f docker-compose.yml $restore_extra \
    exec -T app sh -c 'test -f /tmp/migrate-ran && echo 1 || echo 0' | tr -d '\r\n'
)"
assert_eq "3: recuperação também não migrou" "$migrated_after" "0"

down_stack

echo ""
echo "=== 4) app e queue divergentes abortam sem recreate ==="
new_project
cp "$ROOT_DIR/docker/tests/fixtures/promote-vps/php836.db.mismatch.json" \
  "$TEST_ROOT/.docker/php836.db.override.json"
start_stack_with_overrides
promote_env
cid_before="$(
  cd "$TEST_ROOT"
  docker compose -p "$PROJECT" --env-file .docker/stack.compose.env \
    -f docker-compose.yml -f .docker/php836.override.yml -f .docker/php836.db.override.json \
    ps -q app
)"
set +e
mismatch_out="$(
  cd "$TEST_ROOT"
  promote_env
  sh docker/promote-validated-db-config.sh 2>&1
)"
mismatch_rc=$?
set -e
cid_after="$(
  cd "$TEST_ROOT"
  docker compose -p "$PROJECT" --env-file .docker/stack.compose.env \
    -f docker-compose.yml -f .docker/php836.override.yml -f .docker/php836.db.override.json \
    ps -q app
)"
assert_eq "4: promote aborta com exit 2 (divergência)" "$mismatch_rc" "2"
if [ "$mismatch_rc" -ne 2 ]; then
  echo "$mismatch_out" | tail -40 >&2
fi
assert_eq "4: container app não foi recriado" "$cid_before" "$cid_after"
echo "$mismatch_out" | grep -qi 'divergem' && div=1 || div=0
assert_true "4: log indica divergência app/queue" "$div"
echo "$mismatch_out" | grep -F 'Other$' >/dev/null && mleak=1 || mleak=0
assert_eq "4: divergência não imprime senhas" "$mleak" "0"
canon_mis="$(grep '^GETFY_DB_PASSWORD=' "$TEST_ROOT/.docker/stack.env" | cut -d= -f2- | tr -d '\r')"
secret_eq "4: stack.env permanece truncado (não aplicado)" "$canon_mis" 'CorrectPass!'

down_stack

echo ""
echo "=== Resultado ==="
echo "PASS: $PASS"
echo "FAIL: $FAIL"
if [ "$FAIL" -gt 0 ]; then
  exit 1
fi
echo "Todos os cenários de promoção/recuperação passaram."
exit 0
