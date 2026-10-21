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
echo "=== 0) update.sh preserva antes do Git sync antigo ==="
grep -n 'backup_local_stack_env' "$ROOT_DIR/update.sh" >/dev/null && has_bak=1 || has_bak=0
grep -n 'restore_local_stack_env' "$ROOT_DIR/update.sh" >/dev/null && has_rst=1 || has_rst=0
bak_line="$(grep -n 'backup_local_stack_env' "$ROOT_DIR/update.sh" | grep -v '^.*()' | grep -v 'helper' | head -1 | cut -d: -f1)"
sync_line="$(grep -n 'git-sync-for-deploy.sh' "$ROOT_DIR/update.sh" | head -1 | cut -d: -f1)"
rst_line="$(grep -n 'restore_local_stack_env' "$ROOT_DIR/update.sh" | grep -v '()' | head -1 | cut -d: -f1)"
assert_true "0: update.sh tem backup_local_stack_env" "$has_bak"
assert_true "0: update.sh tem restore_local_stack_env" "$has_rst"
if [ -n "$bak_line" ] && [ -n "$sync_line" ] && [ "$bak_line" -lt "$sync_line" ]; then
  order1=1
else
  order1=0
fi
if [ -n "$rst_line" ] && [ -n "$sync_line" ] && [ "$rst_line" -gt "$sync_line" ]; then
  order2=1
else
  order2=0
fi
assert_true "0: backup roda antes de git-sync-for-deploy.sh" "$order1"
assert_true "0: restore roda depois de git-sync-for-deploy.sh" "$order2"
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

PRESERVE="$TEST_ROOT/preserve"
# Simula o wrap do update.sh novo em volta do git-sync antigo no disco.
sh "$ROOT_DIR/docker/preserve-local-stack-env.sh" backup "$INSTALL" "$PRESERVE"
br="$(git -C "$INSTALL" rev-parse --abbrev-ref HEAD)"
GETFY_REPO_URL="$ORIGIN" sh "$INSTALL/docker/git-sync-for-deploy.sh" "$INSTALL" "$br" ""
# O reset --hard do script antigo apagou o stack.env (já não está no origin).
if [ ! -f "$INSTALL/.docker/stack.env" ]; then
  wiped=1
else
  wiped=0
fi
assert_true "2: git-sync antigo removeu stack.env do worktree (ficheiro saiu do Git)" "$wiped"
sh "$ROOT_DIR/docker/preserve-local-stack-env.sh" restore "$INSTALL" "$PRESERVE"

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

echo ""
echo "=== Resultado ==="
echo "PASS: $PASS"
echo "FAIL: $FAIL"
if [ "$FAIL" -gt 0 ]; then
  exit 1
fi
echo "Cenários de preservação do stack.env no Git sync passaram."
exit 0
