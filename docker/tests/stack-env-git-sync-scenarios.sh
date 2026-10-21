#!/usr/bin/env sh
# Primeiro update de instalação antiga: git-sync com reset --hard não pode
# apagar/alterar o stack.env local. Não imprime valores.
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/getfy-stack-env-git.XXXXXX")"
PASS=0
FAIL=0

cleanup() {
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

secret_same() {
  label="$1"
  a="$2"
  b="$3"
  if [ "$a" = "$b" ]; then
    echo "  PASS: $label"
    PASS=$((PASS + 1))
  else
    echo "  FAIL: $label (valores diferem; não exibidos)" >&2
    FAIL=$((FAIL + 1))
  fi
}

file_hash() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | awk '{print $1}'
  else
    openssl dgst -sha256 "$1" | awk '{print $NF}'
  fi
}

file_mode() {
  stat -c '%a' "$1" 2>/dev/null || stat -f '%OLp' "$1" 2>/dev/null || echo ""
}

git_init_repo() {
  dir="$1"
  mkdir -p "$dir"
  git -C "$dir" init -q
  git -C "$dir" checkout -q -b main 2>/dev/null || true
  git -C "$dir" config user.email "test@example.com"
  git -C "$dir" config user.name "test"
  git -C "$dir" config core.autocrlf false
}

write_tracked_default_stack_env() {
  dest="$1"
  mkdir -p "$dest/.docker" "$dest/docker"
  cat > "$dest/.docker/stack.env" <<'EOF'
GETFY_COMPOSE_PROJECT_NAME=getfy
GETFY_APP_URL=http://localhost
GETFY_HTTP_PORT=80
GETFY_HTTPS_PORT=443
GETFY_DB_PASSWORD=defaultPass
STACKER_AGENT_TOKEN=oldtoken
EOF
}

write_local_live_stack_env() {
  dest="$1"
  mkdir -p "$dest/.docker"
  cat > "$dest/.docker/stack.env" <<'EOF'
GETFY_COMPOSE_PROJECT_NAME=getfy
GETFY_COMPOSE_FILES=docker-compose.caddy.yml
GETFY_APP_URL=https://pay.cliente-exemplo.com.br
GETFY_WEBHOOK_PUBLIC_URL=https://pay.cliente-exemplo.com.br
GETFY_HTTP_PORT=8080
GETFY_HTTPS_PORT=8443
GETFY_DB_USERNAME=getfy_prod
GETFY_DB_PASSWORD=Sec$ret!Pass
STACKER_AGENT_TOKEN=tok_live_keep_this_value
EOF
  chmod 600 "$dest/.docker/stack.env" 2>/dev/null || true
  cat > "$dest/.docker/php836.db.override.json" <<'EOF'
{"services":{"app":{"environment":{"DB_PASSWORD":"Correct$$Pass!"}}}}
EOF
  chmod 600 "$dest/.docker/php836.db.override.json" 2>/dev/null || true
  printf '%s\n' "caddy" > "$dest/.docker/compose-profile"
}

echo ""
echo "=== 0) update.sh / update-caddy.sh / git-sync: trap, wrap, sem ignorar falhas ==="
grep -q 'trap preserve_on_exit EXIT INT TERM' "$ROOT_DIR/update.sh" && trap_u=1 || trap_u=0
grep -q 'trap preserve_on_exit EXIT INT TERM' "$ROOT_DIR/update-caddy.sh" && trap_c=1 || trap_c=0
grep -q 'helper_wrap" wrap' "$ROOT_DIR/update.sh" && wrap_u=1 || wrap_u=0
grep -q 'helper_wrap" wrap' "$ROOT_DIR/update-caddy.sh" && wrap_c=1 || wrap_c=0
grep -q 'PRESERVE_SH" wrap' "$ROOT_DIR/docker/git-sync-for-deploy.sh" && wrap_g=1 || wrap_g=0
grep -q 'backup de stack.env falhou — Git sync abortado' "$ROOT_DIR/update.sh" && bak_abort_u=1 || bak_abort_u=0
grep -q 'backup de stack.env falhou — Git sync abortado' "$ROOT_DIR/update-caddy.sh" && bak_abort_c=1 || bak_abort_c=0
grep -q 'restauração falhou. Backup protegido em:' "$ROOT_DIR/update.sh" && rst_keep_u=1 || rst_keep_u=0
grep -q 'restauração falhou. Backup protegido em:' "$ROOT_DIR/update-caddy.sh" && rst_keep_c=1 || rst_keep_c=0
grep -q 'GETFY_STACK_ENV_PRESERVE_OWNED' "$ROOT_DIR/docker/git-sync-for-deploy.sh" && owned=1 || owned=0
grep -q 'trap on_exit EXIT INT TERM' "$ROOT_DIR/docker/git-sync-for-deploy.sh" && trap_g=1 || trap_g=0
grep -E 'backup_local_stack_env[[:space:]]*\|\|[[:space:]]*true' "$ROOT_DIR/update.sh" >/dev/null && ignore_bak_u=1 || ignore_bak_u=0
grep -E 'restore_local_stack_env[[:space:]]*\|\|[[:space:]]*true' "$ROOT_DIR/update.sh" >/dev/null && ignore_rst_u=1 || ignore_rst_u=0
grep -E 'backup_local_stack_env[[:space:]]*\|\|[[:space:]]*true' "$ROOT_DIR/update-caddy.sh" >/dev/null && ignore_bak_c=1 || ignore_bak_c=0
grep -E 'restore_local_stack_env[[:space:]]*\|\|[[:space:]]*true' "$ROOT_DIR/update-caddy.sh" >/dev/null && ignore_rst_c=1 || ignore_rst_c=0
assert_true "0: update.sh restaura em EXIT/INT/TERM" "$trap_u"
assert_true "0: update-caddy.sh restaura em EXIT/INT/TERM" "$trap_c"
assert_true "0: update.sh usa wrap do helper" "$wrap_u"
assert_true "0: update-caddy.sh usa wrap do helper" "$wrap_c"
assert_true "0: git-sync usa wrap do helper" "$wrap_g"
assert_true "0: update.sh aborta se o backup falhar" "$bak_abort_u"
assert_true "0: update-caddy.sh aborta se o backup falhar" "$bak_abort_c"
assert_true "0: update.sh mantém backup se o restore falhar" "$rst_keep_u"
assert_true "0: update-caddy.sh mantém backup se o restore falhar" "$rst_keep_c"
assert_true "0: git-sync respeita OWNED (não apaga backup do pai)" "$owned"
assert_true "0: git-sync restaura em EXIT/INT/TERM (fallback sem helper)" "$trap_g"
assert_eq "0: update.sh não ignora falha de backup" "$ignore_bak_u" "0"
assert_eq "0: update.sh não ignora falha de restore" "$ignore_rst_u" "0"
assert_eq "0: update-caddy.sh não ignora falha de backup" "$ignore_bak_c" "0"
assert_eq "0: update-caddy.sh não ignora falha de restore" "$ignore_rst_c" "0"
grep -q 'stack.env.example' "$ROOT_DIR/.gitignore" && ex=1 || ex=0
assert_true "0: gitignore permite stack.env.example" "$ex"

echo ""
echo "=== 1) git-sync antigo sozinho perde o stack.env local (o bug) ==="
ORIGIN="$TEST_ROOT/origin.git"
INSTALL_BUG="$TEST_ROOT/install-bug"
git_init_repo "$ORIGIN"
write_tracked_default_stack_env "$ORIGIN"
cp "$ROOT_DIR/docker/tests/fixtures/old-git-sync/git-sync-for-deploy.sh" \
  "$ORIGIN/docker/git-sync-for-deploy.sh"
cat > "$ORIGIN/.gitignore" <<'EOF'
/.docker/stack.env
!/.docker/stack.env.example
EOF
printf '%s\n' "old" > "$ORIGIN/VERSION"
git -C "$ORIGIN" add -A
git -C "$ORIGIN" add -f .docker/stack.env
git -C "$ORIGIN" commit -q -m "old: stack.env versionado"
git -C "$ORIGIN" tag old
git clone -q "$ORIGIN" "$INSTALL_BUG"
write_local_live_stack_env "$INSTALL_BUG"
cp "$ROOT_DIR/docker/tests/fixtures/old-git-sync/git-sync-for-deploy.sh" \
  "$INSTALL_BUG/docker/git-sync-for-deploy.sh"
hash_live="$(file_hash "$INSTALL_BUG/.docker/stack.env")"
(
  cd "$INSTALL_BUG"
  GETFY_REPO_URL="$ORIGIN" sh docker/git-sync-for-deploy.sh "$INSTALL_BUG" master "" 2>/dev/null \
    || GETFY_REPO_URL="$ORIGIN" sh docker/git-sync-for-deploy.sh "$INSTALL_BUG" main "" 2>/dev/null \
    || true
)
if [ -f "$INSTALL_BUG/.docker/stack.env" ]; then
  hash_after_bug="$(file_hash "$INSTALL_BUG/.docker/stack.env")"
else
  hash_after_bug="MISSING"
fi
if [ "$hash_after_bug" != "$hash_live" ]; then
  lost=1
else
  lost=0
fi
assert_true "1: sem backup, reset --hard altera/apaga o stack.env local" "$lost"

echo ""
echo "=== 2) primeiro update (update.sh novo + git-sync antigo) preserva tudo ==="
INSTALL="$TEST_ROOT/install"
git clone -q "$ORIGIN" "$INSTALL"
write_local_live_stack_env "$INSTALL"
cp "$ROOT_DIR/docker/tests/fixtures/old-git-sync/git-sync-for-deploy.sh" \
  "$INSTALL/docker/git-sync-for-deploy.sh"
chmod 600 "$INSTALL/.docker/stack.env" "$INSTALL/.docker/php836.db.override.json" 2>/dev/null || true
mode_env="$(file_mode "$INSTALL/.docker/stack.env")"
mode_ov="$(file_mode "$INSTALL/.docker/php836.db.override.json")"
hash_env="$(file_hash "$INSTALL/.docker/stack.env")"
hash_ov="$(file_hash "$INSTALL/.docker/php836.db.override.json")"
hash_profile="$(file_hash "$INSTALL/.docker/compose-profile")"
cp "$INSTALL/.docker/stack.env" "$TEST_ROOT/stack.env.before"

# Origin novo: remove stack.env, adiciona example + helper + git-sync novo.
cp "$ROOT_DIR/docker/preserve-local-stack-env.sh" "$ORIGIN/docker/preserve-local-stack-env.sh"
cp "$ROOT_DIR/docker/git-sync-for-deploy.sh" "$ORIGIN/docker/git-sync-for-deploy.sh"
cp "$ROOT_DIR/.docker/stack.env.example" "$ORIGIN/.docker/stack.env.example"
git -C "$ORIGIN" rm -q --cached .docker/stack.env 2>/dev/null || true
rm -f "$ORIGIN/.docker/stack.env"
git -C "$ORIGIN" add docker/preserve-local-stack-env.sh docker/git-sync-for-deploy.sh \
  .docker/stack.env.example .gitignore
git -C "$ORIGIN" commit -q -m "new: stack.env fora do git + preserve"

WRAP2="$TEST_ROOT/preserve-wrap"
mkdir -p "$WRAP2"
br="$(git -C "$INSTALL" rev-parse --abbrev-ref HEAD)"
# Wrapper real (o mesmo que update.sh chama quando o helper existe).
rc2=0
sh "$ROOT_DIR/docker/preserve-local-stack-env.sh" wrap "$INSTALL" "$WRAP2" -- \
  env GETFY_REPO_URL="$ORIGIN" GETFY_STACK_ENV_PRESERVE_OWNED=1 \
    sh "$INSTALL/docker/git-sync-for-deploy.sh" "$INSTALL" "$br" "" \
  >"$TEST_ROOT/wrap2.out" 2>"$TEST_ROOT/wrap2.err" || rc2=$?
assert_eq "2: wrap do primeiro update concluiu (rc 0)" "$rc2" "0"

[ -f "$INSTALL/.docker/stack.env" ] && back=1 || back=0
assert_true "2: restore recolocou stack.env" "$back"
secret_same "2: stack.env byte-a-byte (domínio/portas/senha/token)" \
  "$(file_hash "$INSTALL/.docker/stack.env")" "$hash_env"
secret_same "2: override JSON intacto" \
  "$(file_hash "$INSTALL/.docker/php836.db.override.json")" "$hash_ov"
secret_same "2: compose-profile intacto" \
  "$(file_hash "$INSTALL/.docker/compose-profile")" "$hash_profile"
assert_eq "2: modo de stack.env preservado" "$(file_mode "$INSTALL/.docker/stack.env")" "$mode_env"
assert_eq "2: modo do override preservado" "$(file_mode "$INSTALL/.docker/php836.db.override.json")" "$mode_ov"
cmp -s "$TEST_ROOT/stack.env.before" "$INSTALL/.docker/stack.env" && exact=1 || exact=0
assert_true "2: cmp silencioso confirma identidade" "$exact"
# example chegou; arquivo real não é o example
if [ -f "$INSTALL/.docker/stack.env.example" ]; then
  cmp -s "$INSTALL/.docker/stack.env" "$INSTALL/.docker/stack.env.example" && same_ex=1 || same_ex=0
  assert_eq "2: stack.env restaurado NÃO é o example" "$same_ex" "0"
else
  echo "  FAIL: 2: stack.env.example não veio no update" >&2
  FAIL=$((FAIL + 1))
fi

echo ""
echo "=== 3) git-sync novo sozinho também restaura (defesa) ==="
INSTALL2="$TEST_ROOT/install2"
git clone -q --branch old "$ORIGIN" "$INSTALL2"
write_local_live_stack_env "$INSTALL2"
hash2="$(file_hash "$INSTALL2/.docker/stack.env")"
cp "$ROOT_DIR/docker/preserve-local-stack-env.sh" "$INSTALL2/docker/preserve-local-stack-env.sh"
cp "$ROOT_DIR/docker/git-sync-for-deploy.sh" "$INSTALL2/docker/git-sync-for-deploy.sh"
GETFY_REPO_URL="$ORIGIN" sh "$INSTALL2/docker/git-sync-for-deploy.sh" "$INSTALL2" "$br" ""
secret_same "3: stack.env sobrevive ao git-sync novo" \
  "$(file_hash "$INSTALL2/.docker/stack.env")" "$hash2"

stderr_leaks_secrets() {
  errf="$1"
  if grep -F 'Sec$ret!Pass' "$errf" >/dev/null 2>&1; then
    return 0
  fi
  if grep -F 'tok_live_keep_this_value' "$errf" >/dev/null 2>&1; then
    return 0
  fi
  return 1
}

echo ""
echo "=== 4) wrap: sync modifica a configuração e sai com erro ==="
INSTALL4="$TEST_ROOT/install4"
write_local_live_stack_env "$INSTALL4"
hash4="$(file_hash "$INSTALL4/.docker/stack.env")"
cp "$INSTALL4/.docker/stack.env" "$TEST_ROOT/stack4.before"
WRAP4="$TEST_ROOT/wrap4"
mkdir -p "$WRAP4"
marker4="$TEST_ROOT/sync4-ran"
rc4=0
sh "$ROOT_DIR/docker/preserve-local-stack-env.sh" wrap "$INSTALL4" "$WRAP4" -- \
  sh -c 'printf "WIPED_BY_FAILING_SYNC\n" > "$1/.docker/stack.env"; echo ran > "$2"; exit 7' \
  sh "$INSTALL4" "$marker4" \
  >"$TEST_ROOT/wrap4.out" 2>"$TEST_ROOT/wrap4.err" || rc4=$?
assert_eq "4: rc original do sync (7) preservado" "$rc4" "7"
[ -f "$marker4" ] && ran4=1 || ran4=0
assert_true "4: o comando de sync chegou a executar" "$ran4"
secret_same "4: stack.env restaurado após falha do sync" \
  "$(file_hash "$INSTALL4/.docker/stack.env")" "$hash4"
cmp -s "$TEST_ROOT/stack4.before" "$INSTALL4/.docker/stack.env" && exact4=1 || exact4=0
assert_true "4: cmp confirma identidade após restore" "$exact4"
[ ! -e "$WRAP4" ] && gone4=1 || gone4=0
assert_true "4: backup apagado só após restore confirmado" "$gone4"
if stderr_leaks_secrets "$TEST_ROOT/wrap4.err"; then
  echo "  FAIL: 4: stderr vazou valor de senha/token" >&2
  FAIL=$((FAIL + 1))
else
  echo "  PASS: 4: stderr não revela valores"
  PASS=$((PASS + 1))
fi

echo ""
echo "=== 5) wrap: falha no backup — sync não executa ==="
INSTALL5="$TEST_ROOT/install5"
write_local_live_stack_env "$INSTALL5"
hash5="$(file_hash "$INSTALL5/.docker/stack.env")"
WRAP5="$TEST_ROOT/wrap5"
mkdir -p "$WRAP5"
marker5="$TEST_ROOT/sync5-must-not-run"
rc5=0
GETFY_PRESERVE_FAIL_BACKUP=1 \
  sh "$ROOT_DIR/docker/preserve-local-stack-env.sh" wrap "$INSTALL5" "$WRAP5" -- \
  sh -c 'echo ran > "$1"; printf "WIPED\n" > "$2/.docker/stack.env"' \
  sh "$marker5" "$INSTALL5" \
  >"$TEST_ROOT/wrap5.out" 2>"$TEST_ROOT/wrap5.err" || rc5=$?
if [ "$rc5" -ne 0 ]; then
  failbak=1
else
  failbak=0
fi
assert_true "5: wrap aborta quando o backup falha" "$failbak"
[ ! -f "$marker5" ] && no_run5=1 || no_run5=0
assert_true "5: sync não rodou após falha do backup" "$no_run5"
secret_same "5: stack.env intacto (sync não rodou)" \
  "$(file_hash "$INSTALL5/.docker/stack.env")" "$hash5"
[ -d "$WRAP5" ] && keep5=1 || keep5=0
assert_true "5: diretório de backup permanece protegido" "$keep5"
grep -q "$WRAP5" "$TEST_ROOT/wrap5.err" && path5=1 || path5=0
assert_true "5: stderr informa o caminho do backup" "$path5"
grep -q 'backup falhou' "$TEST_ROOT/wrap5.err" && msg5=1 || msg5=0
assert_true "5: stderr diz que o backup falhou" "$msg5"
if stderr_leaks_secrets "$TEST_ROOT/wrap5.err"; then
  echo "  FAIL: 5: stderr vazou valor de senha/token" >&2
  FAIL=$((FAIL + 1))
else
  echo "  PASS: 5: stderr não revela valores"
  PASS=$((PASS + 1))
fi

echo ""
echo "=== 6) wrap: falha na restauração — backup permanece ==="
INSTALL6="$TEST_ROOT/install6"
write_local_live_stack_env "$INSTALL6"
cp "$INSTALL6/.docker/stack.env" "$TEST_ROOT/stack6.before"
WRAP6="$TEST_ROOT/wrap6"
mkdir -p "$WRAP6"
marker6="$TEST_ROOT/sync6-ran"
rc6=0
GETFY_PRESERVE_FAIL_RESTORE=1 \
  sh "$ROOT_DIR/docker/preserve-local-stack-env.sh" wrap "$INSTALL6" "$WRAP6" -- \
  sh -c 'printf "WIPED_AFTER_SYNC\n" > "$1/.docker/stack.env"; echo ran > "$2"' \
  sh "$INSTALL6" "$marker6" \
  >"$TEST_ROOT/wrap6.out" 2>"$TEST_ROOT/wrap6.err" || rc6=$?
if [ "$rc6" -ne 0 ]; then
  failrst=1
else
  failrst=0
fi
assert_true "6: wrap retorna erro quando o restore falha" "$failrst"
[ -f "$marker6" ] && ran6=1 || ran6=0
assert_true "6: o comando de sync chegou a executar" "$ran6"
[ -d "$WRAP6" ] && keep6=1 || keep6=0
assert_true "6: backup NÃO foi apagado após restore falhar" "$keep6"
[ -f "$WRAP6/.docker/stack.env" ] && bakfile6=1 || bakfile6=0
assert_true "6: ficheiro de backup de stack.env permanece" "$bakfile6"
secret_same "6: conteúdo do backup é o original" \
  "$(file_hash "$WRAP6/.docker/stack.env")" "$(file_hash "$TEST_ROOT/stack6.before")"
grep -q "$WRAP6" "$TEST_ROOT/wrap6.err" && path6=1 || path6=0
assert_true "6: stderr informa o caminho do backup" "$path6"
grep -q 'restauração falhou' "$TEST_ROOT/wrap6.err" && msg6=1 || msg6=0
assert_true "6: stderr diz que a restauração falhou" "$msg6"
if stderr_leaks_secrets "$TEST_ROOT/wrap6.err"; then
  echo "  FAIL: 6: stderr vazou valor de senha/token" >&2
  FAIL=$((FAIL + 1))
else
  echo "  PASS: 6: stderr não revela valores"
  PASS=$((PASS + 1))
fi

echo ""
echo "=== Resultado ==="
echo "PASS: $PASS"
echo "FAIL: $FAIL"
if [ "$FAIL" -gt 0 ]; then
  exit 1
fi
echo "Cenários de preservação do stack.env no Git sync passaram."
exit 0
