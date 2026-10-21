#!/usr/bin/env sh
# Regressões: socket trust vs TCP, divergência de fontes, cifrão no Compose, cache antigo.
# Não toca na stack de desenvolvimento (stacker-gateway) nem em volumes de produção.
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/getfy-db-cred.XXXXXX")"
PASS=0
FAIL=0

cleanup() {
  if [ -n "${PG_TEST_CID:-}" ]; then
    docker rm -f "$PG_TEST_CID" >/dev/null 2>&1 || true
  fi
  rm -rf "$TEST_ROOT"
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

echo ""
echo "=== 1) write-compose-env duplica cifrões ==="
mkdir -p "$TEST_ROOT/compose-escape/.docker"
cp "$ROOT_DIR/docker/write-compose-env.sh" "$TEST_ROOT/compose-escape/docker/write-compose-env.sh" 2>/dev/null || {
  mkdir -p "$TEST_ROOT/compose-escape/docker"
  cp "$ROOT_DIR/docker/write-compose-env.sh" "$TEST_ROOT/compose-escape/docker/write-compose-env.sh"
}
cat > "$TEST_ROOT/compose-escape/.docker/stack.env" <<'EOF'
GETFY_DB_USERNAME=getfy_prod
GETFY_DB_PASSWORD=p$rod!x${HOME}end
GETFY_DB_DATABASE=getfy
APP_KEY=base64:KeepMeIntactxxxxxxxxxxxxxxxxxxxx=
EOF
chmod +x "$TEST_ROOT/compose-escape/docker/write-compose-env.sh"
out="$(
  cd "$TEST_ROOT/compose-escape"
  sh docker/write-compose-env.sh
)"
escaped_pass="$(grep '^GETFY_DB_PASSWORD=' "$TEST_ROOT/compose-escape/.docker/stack.compose.env" | cut -d= -f2-)"
canon_pass="$(grep '^GETFY_DB_PASSWORD=' "$TEST_ROOT/compose-escape/.docker/stack.env" | cut -d= -f2-)"
key_after="$(grep '^APP_KEY=' "$TEST_ROOT/compose-escape/.docker/stack.env" | cut -d= -f2-)"
assert_eq "1: path stack.compose.env" "$out" ".docker/stack.compose.env"
assert_eq "1: canônico preserva \$" "$canon_pass" 'p$rod!x${HOME}end'
assert_eq "1: compose file duplica \$" "$escaped_pass" 'p$$rod!x$${HOME}end'
assert_eq "1: APP_KEY intocado no canônico" "$key_after" "base64:KeepMeIntactxxxxxxxxxxxxxxxxxxxx="

echo ""
echo "=== 2) Compose env-file escapado entrega a senha com cifrão ==="
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  mkdir -p "$TEST_ROOT/compose-interp/.docker" "$TEST_ROOT/compose-interp/docker"
  cp "$ROOT_DIR/docker/write-compose-env.sh" "$TEST_ROOT/compose-interp/docker/write-compose-env.sh"
  printf '%s\n' 'GETFY_DB_PASSWORD=Sec$ret!Pass' > "$TEST_ROOT/compose-interp/.docker/stack.env"
  (
    cd "$TEST_ROOT/compose-interp"
    sh docker/write-compose-env.sh >/dev/null
  )
  cat > "$TEST_ROOT/compose-interp/docker-compose.yml" <<'EOF'
services:
  probe:
    image: alpine
    environment:
      DB_PASSWORD: ${GETFY_DB_PASSWORD:-missing}
EOF
  cfg="$(
    cd "$TEST_ROOT/compose-interp"
    docker compose --env-file .docker/stack.compose.env config 2>/dev/null || true
  )"
  echo "$cfg" | grep -F 'Sec$$ret!Pass' >/dev/null && interp_ok=1 || interp_ok=0
  echo "$cfg" | grep -F 'missing' >/dev/null && lost=1 || lost=0
  assert_true "2: compose config preserva senha com cifrão escapado" "$interp_ok"
  assert_eq "2: default 'missing' NÃO venceu" "$lost" "0"
else
  echo "  SKIP: docker compose indisponível"
fi

echo ""
echo "=== 3) Socket local (trust) aceita senha errada; TCP rejeita ==="
PG_TEST_CID=""
if command -v docker >/dev/null 2>&1; then
  PG_TEST_CID="$(docker run -d --rm \
    -e POSTGRES_USER=getfy \
    -e POSTGRES_PASSWORD=CorrectPass123 \
    -e POSTGRES_DB=getfy \
    postgres:16-alpine)"
  ready=0
  i=0
  while [ "$i" -lt 40 ]; do
    if docker exec "$PG_TEST_CID" pg_isready -U getfy -d getfy >/dev/null 2>&1; then
      ready=1
      break
    fi
    i=$((i + 1))
    sleep 1
  done
  assert_eq "3: postgres de teste ready" "$ready" "1"
  set +e
  docker exec -e PGPASSWORD=WrongPass "$PG_TEST_CID" \
    psql -U getfy -d getfy -w -c 'SELECT 1' >/dev/null 2>&1
  sock_wrong=$?
  pg_ip="$(docker exec "$PG_TEST_CID" hostname -i | awk '{print $1}')"
  docker exec -e PGPASSWORD=WrongPass "$PG_TEST_CID" \
    psql -h "$pg_ip" -U getfy -d getfy -w -c 'SELECT 1' >/dev/null 2>&1
  tcp_wrong=$?
  docker exec -e PGPASSWORD=CorrectPass123 "$PG_TEST_CID" \
    psql -h "$pg_ip" -U getfy -d getfy -w -c 'SELECT 1' >/dev/null 2>&1
  tcp_ok=$?
  set -e
  assert_eq "3: socket+senha errada (trust) exit 0" "$sock_wrong" "0"
  assert_true "3: TCP+senha errada falha" "$([ "$tcp_wrong" -ne 0 ] && echo 1 || echo 0)"
  assert_eq "3: TCP+senha correta exit 0" "$tcp_ok" "0"
else
  echo "  SKIP: docker indisponível"
fi

echo ""
echo "=== 4) ensure-db-credentials: stack.env errado (trust) não vence; .env TCP vence; APP_KEY intacta ==="
MOCK_BIN="$TEST_ROOT/bin"
STATE="$TEST_ROOT/state"
mkdir -p "$MOCK_BIN" "$STATE" "$TEST_ROOT/project/.docker" "$TEST_ROOT/project/docker"
printf '%s\n' "CorrectPass123" > "$STATE/correct_pass"
printf '%s\n' "getfy_prod" > "$STATE/correct_user"
cat > "$MOCK_BIN/docker" <<'MOCK'
#!/usr/bin/env sh
set -eu
STATE="${GETFY_TEST_STATE:?}"
cmd="${1:-}"
shift || true
if [ "$cmd" = "volume" ]; then
  echo "getfy_postgres_data"
  exit 0
fi
if [ "$cmd" = "run" ]; then
  exit 0
fi
if [ "$cmd" = "ps" ]; then
  echo "getfy-postgres-1"
  echo "getfy-app-1"
  exit 0
fi
if [ "$cmd" = "exec" ]; then
  pgpass=""
  tcp=0
  user=""
  while [ "$#" -gt 0 ]; do
    case "$1" in
      -e)
        shift
        case "${1:-}" in
          PGPASSWORD=*) pgpass="${1#PGPASSWORD=}" ;;
        esac
        shift || true
        ;;
      hostname)
        echo "172.17.0.2"
        exit 0
        ;;
      -h)
        shift
        [ -n "${1:-}" ] && [ "${1:-}" != "127.0.0.1" ] && tcp=1
        shift || true
        ;;
      -U)
        shift
        user="${1:-}"
        shift || true
        ;;
      printenv)
        shift
        key="${1:-}"
        if [ "$key" = "POSTGRES_USER" ] || [ "$key" = "DB_USERNAME" ]; then
          cat "$STATE/correct_user"
        elif [ "$key" = "POSTGRES_PASSWORD" ] || [ "$key" = "DB_PASSWORD" ]; then
          cat "$STATE/correct_pass"
        fi
        exit 0
        ;;
      *) shift || true ;;
    esac
  done
  want_u="$(cat "$STATE/correct_user")"
  want_p="$(cat "$STATE/correct_pass")"
  if [ "$tcp" -eq 0 ]; then
    # Socket trust: qualquer senha "funciona".
    exit 0
  fi
  if [ "$user" = "$want_u" ] && [ "$pgpass" = "$want_p" ]; then
    exit 0
  fi
  exit 1
fi
exit 0
MOCK
chmod +x "$MOCK_BIN/docker"
cp "$ROOT_DIR/docker/ensure-db-credentials.sh" "$TEST_ROOT/project/docker/ensure-db-credentials.sh"
cp "$ROOT_DIR/docker/write-compose-env.sh" "$TEST_ROOT/project/docker/write-compose-env.sh"
cp "$ROOT_DIR/docker/unset-db-shell-env.sh" "$TEST_ROOT/project/docker/unset-db-shell-env.sh"
cat > "$TEST_ROOT/project/.docker/stack.env" <<'EOF'
GETFY_DB_USERNAME=getfy
GETFY_DB_PASSWORD=WrongFromStack
GETFY_DB_DATABASE=getfy
GETFY_COMPOSE_PROJECT_NAME=getfy
EOF
cat > "$TEST_ROOT/project/.env" <<'EOF'
GETFY_DB_USERNAME=getfy_prod
GETFY_DB_PASSWORD=CorrectPass123
APP_KEY=base64:ExistingAppKeyMustPersistXXXXXXXXXXXX=
STACKER_AGENT_TOKEN=keep-this-token
EOF
chmod 600 "$TEST_ROOT/project/.docker/stack.env" "$TEST_ROOT/project/.env"
set +e
ensure_out="$(
  cd "$TEST_ROOT/project"
  PATH="$MOCK_BIN:$PATH" GETFY_TEST_STATE="$STATE" GETFY_TEST_PG_CONTAINER=getfy-postgres-1 \
    sh docker/ensure-db-credentials.sh 2>&1
)"
ensure_rc=$?
set -e
user_after="$(grep '^GETFY_DB_USERNAME=' "$TEST_ROOT/project/.docker/stack.env" | cut -d= -f2-)"
pass_after="$(grep '^GETFY_DB_PASSWORD=' "$TEST_ROOT/project/.docker/stack.env" | cut -d= -f2-)"
key_after="$(grep '^APP_KEY=' "$TEST_ROOT/project/.env" | cut -d= -f2-)"
token_after="$(grep '^STACKER_AGENT_TOKEN=' "$TEST_ROOT/project/.env" | cut -d= -f2-)"
assert_eq "4: ensure exit 0" "$ensure_rc" "0"
assert_eq "4: stack.env user veio do .env válido (TCP)" "$user_after" "getfy_prod"
assert_eq "4: stack.env senha veio do .env válido" "$pass_after" "CorrectPass123"
assert_eq "4: APP_KEY preservada" "$key_after" "base64:ExistingAppKeyMustPersistXXXXXXXXXXXX="
assert_eq "4: token preservado" "$token_after" "keep-this-token"
echo "$ensure_out" | grep -q 'WrongFromStack' && leaked=1 || leaked=0
assert_eq "4: senha inválida não aparece no log" "$leaked" "0"
echo "$ensure_out" | grep -qi '\.env' && via_env=1 || via_env=0
assert_true "4: log indica fonte .env" "$via_env"

echo ""
echo "=== 5) divergência: só volume autentica por TCP ==="
cat > "$TEST_ROOT/project/.docker/stack.env" <<'EOF'
GETFY_DB_USERNAME=getfy
GETFY_DB_PASSWORD=WrongFromStack
GETFY_DB_DATABASE=getfy
GETFY_COMPOSE_PROJECT_NAME=getfy
EOF
cat > "$TEST_ROOT/project/.env" <<'EOF'
GETFY_DB_USERNAME=getfy
GETFY_DB_PASSWORD=AlsoWrong
APP_KEY=base64:ExistingAppKeyMustPersistXXXXXXXXXXXX=
EOF
# docker run alpine cat /v/stack.env — o mock `docker run` sempre exit 0 e não imprime.
# Ajusta mock para devolver volume env quando `cat`.
cat > "$MOCK_BIN/docker" <<'MOCK'
#!/usr/bin/env sh
set -eu
STATE="${GETFY_TEST_STATE:?}"
cmd="${1:-}"
shift || true
if [ "$cmd" = "volume" ]; then
  echo "getfy_postgres_data"
  echo "getfy_getfy_env"
  exit 0
fi
if [ "$cmd" = "run" ]; then
  for a in "$@"; do
    if [ "$a" = "cat" ]; then
      printf '%s\n' "GETFY_DB_USERNAME=getfy_prod"
      printf '%s\n' "GETFY_DB_PASSWORD=CorrectPass123"
      exit 0
    fi
  done
  exit 0
fi
if [ "$cmd" = "ps" ]; then
  echo "getfy-postgres-1"
  exit 0
fi
if [ "$cmd" = "exec" ]; then
  pgpass=""; tcp=0; user=""
  while [ "$#" -gt 0 ]; do
    case "$1" in
      -e) shift; case "${1:-}" in PGPASSWORD=*) pgpass="${1#PGPASSWORD=}";; esac; shift || true ;;
      hostname) echo "172.17.0.2"; exit 0 ;;
      -h) shift; [ -n "${1:-}" ] && [ "${1:-}" != "127.0.0.1" ] && tcp=1; shift || true ;;
      -U) shift; user="${1:-}"; shift || true ;;
      printenv) exit 1 ;;
      *) shift || true ;;
    esac
  done
  if [ "$tcp" -eq 0 ]; then exit 0; fi
  [ "$user" = "getfy_prod" ] && [ "$pgpass" = "CorrectPass123" ] && exit 0
  exit 1
fi
exit 0
MOCK
chmod +x "$MOCK_BIN/docker"
set +e
vol_out="$(
  cd "$TEST_ROOT/project"
  PATH="$MOCK_BIN:$PATH" GETFY_TEST_STATE="$STATE" GETFY_TEST_PG_CONTAINER=getfy-postgres-1 \
    sh docker/ensure-db-credentials.sh 2>&1
)"
vol_rc=$?
set -e
vol_user="$(grep '^GETFY_DB_USERNAME=' "$TEST_ROOT/project/.docker/stack.env" | cut -d= -f2-)"
vol_key="$(grep '^APP_KEY=' "$TEST_ROOT/project/.env" | cut -d= -f2-)"
assert_eq "5: ensure exit 0 via volume" "$vol_rc" "0"
assert_eq "5: user sincronizado do volume" "$vol_user" "getfy_prod"
assert_eq "5: APP_KEY ainda intacta" "$vol_key" "base64:ExistingAppKeyMustPersistXXXXXXXXXXXX="
echo "$vol_out" | grep -qi 'volume' && via_vol=1 || via_vol=0
assert_true "5: log indica volume" "$via_vol"

echo ""
echo "=== 6) cache de config de outro ambiente não entra na imagem ==="
grep -q 'bootstrap/cache/\*\.php' "$ROOT_DIR/.dockerignore" && ign=1 || ign=0
assert_true "6: .dockerignore exclui bootstrap/cache/*.php" "$ign"
grep -q 'rm -f bootstrap/cache/config.php' "$ROOT_DIR/Dockerfile" && dkr=1 || dkr=0
assert_true "6: Dockerfile remove config.php após COPY" "$dkr"
grep -q 'rm -f bootstrap/cache/config.php' "$ROOT_DIR/docker/php-base-from-running.Dockerfile" && fromr=1 || fromr=0
assert_true "6: php-base-from-running remove config.php" "$fromr"
grep -q 'GETFY_SKIP_MIGRATE' "$ROOT_DIR/docker/entrypoint.sh" && skipm=1 || skipm=0
assert_true "6: entrypoint respeita GETFY_SKIP_MIGRATE" "$skipm"
grep -q 'getenv("DB_USERNAME") ?: "getfy"' "$ROOT_DIR/docker/entrypoint.sh" && defu=1 || defu=0
assert_eq "6: entrypoint NÃO defaulta DB_USERNAME=getfy" "$defu" "0"

echo ""
echo "=== 7) prune não usa prune -af (preserva rollback) ==="
grep -E '^[[:space:]]*docker image prune -af' "$ROOT_DIR/docker/prune-docker-images.sh" >/dev/null && paf=1 || paf=0
assert_eq "7: prune-docker-images sem prune -af" "$paf" "0"
grep -q 'rollback' "$ROOT_DIR/docker/prune-docker-images.sh" && prot=1 || prot=0
assert_true "7: prune menciona preservação de rollback" "$prot"

echo ""
echo "=== 8) healthcheck exige SELECT 1 (não só /up) ==="
grep -q 'SELECT 1' "$ROOT_DIR/docker/post-update-healthcheck.sh" && sel=1 || sel=0
assert_true "8: post-update-healthcheck faz SELECT 1" "$sel"
grep -q '/up sozinho não basta' "$ROOT_DIR/docker/post-update-healthcheck.sh" && msg=1 || msg=0
assert_true "8: mensagem de que /up não basta" "$msg"

echo ""
echo "=== 9) unset por prefixo (não glob; não toca DBUS) ==="
# shellcheck disable=SC1091
. "$ROOT_DIR/docker/unset-db-shell-env.sh"
export GETFY_DB_PASSWORD='Sec$ret'
export GETFY_DB_USERNAME='getfy_prod'
export DB_PASSWORD='also'
export DB_USERNAME='u'
export DBUS_SESSION_BUS_ADDRESS='keep-dbus'
export GETFY_APP_URL='https://keep.example'
unset_db_shell_env
[ -z "${GETFY_DB_PASSWORD+x}" ] && u1=1 || u1=0
[ -z "${GETFY_DB_USERNAME+x}" ] && u2=1 || u2=0
[ -z "${DB_PASSWORD+x}" ] && u3=1 || u3=0
[ -n "${DBUS_SESSION_BUS_ADDRESS+x}" ] && u4=1 || u4=0
[ -n "${GETFY_APP_URL+x}" ] && u5=1 || u5=0
assert_true "9: GETFY_DB_PASSWORD removida" "$u1"
assert_true "9: GETFY_DB_USERNAME removida" "$u2"
assert_true "9: DB_PASSWORD removida" "$u3"
assert_true "9: DBUS_SESSION_BUS_ADDRESS preservada" "$u4"
assert_true "9: GETFY_APP_URL preservada" "$u5"
unset GETFY_APP_URL DBUS_SESSION_BUS_ADDRESS 2>/dev/null || true

echo ""
echo "=== 10) GETFY_LEGACY_GIT_UPDATE usa stack.compose.env validado ==="
grep -q 'write-compose-env.sh' "$ROOT_DIR/update.sh" && leg1=1 || leg1=0
grep -q 'unset-db-shell-env.sh' "$ROOT_DIR/update.sh" && leg2=1 || leg2=0
grep -q 'unset GETFY_DB_\*' "$ROOT_DIR/update.sh" && leg3=1 || leg3=0
grep -q 'db_env_u_flags' "$ROOT_DIR/update.sh" && leg4=1 || leg4=0
assert_true "10: update.sh gera stack.compose.env" "$leg1"
assert_true "10: update.sh carrega unset por prefixo" "$leg2"
assert_eq "10: update.sh não usa glob GETFY_DB_*" "$leg3" "0"
assert_true "10: update.sh passa -u via db_env_u_flags ao sudo" "$leg4"

echo ""
echo "=== 11) promote pin da imagem em execução + rollback VPS ==="
grep -q 'getfy_app:rollback-app-pre-php836' "$ROOT_DIR/docker/promote-validated-db-config.sh" && rb1=1 || rb1=0
grep -q 'getfy_app:rollback-queue-pre-php836' "$ROOT_DIR/docker/promote-validated-db-config.sh" && rb2=1 || rb2=0
grep -q 'getfy_app:running-php836' "$ROOT_DIR/docker/promote-validated-db-config.sh" && pin=1 || pin=0
grep -q 'restore_running' "$ROOT_DIR/docker/promote-validated-db-config.sh" && rec=1 || rec=0
grep -q 'stack.env.orig' "$ROOT_DIR/docker/promote-validated-db-config.sh" && bak=1 || bak=0
assert_true "11: documenta rollback-app-pre-php836" "$rb1"
assert_true "11: documenta rollback-queue-pre-php836" "$rb2"
assert_true "11: pin getfy_app:running-php836" "$pin"
assert_true "11: restore_running em falha" "$rec"
assert_true "11: backup stack.env.orig" "$bak"

echo ""
echo "=== 12) php-base-from-running não copia /usr da imagem antiga ==="
grep -q 'COPY --from=current /var/www/html' "$ROOT_DIR/docker/php-base-from-running.Dockerfile" && html=1 || html=0
grep -q 'COPY --from=current /usr' "$ROOT_DIR/docker/php-base-from-running.Dockerfile" && usr=1 || usr=0
grep -q 'code-from-running' "$ROOT_DIR/docker/apply-official-php-base.sh" && cfr=1 || cfr=0
assert_true "12: copia só /var/www/html da imagem em execução" "$html"
assert_eq "12: NÃO copia /usr (ABI PHP antiga)" "$usr" "0"
assert_true "12: apply usa code-from-running do container app" "$cfr"

echo ""
echo "=== Resultado ==="
echo "PASS: $PASS"
echo "FAIL: $FAIL"
if [ "$FAIL" -gt 0 ]; then
  exit 1
fi
echo "Todos os cenários de credenciais passaram."
exit 0
