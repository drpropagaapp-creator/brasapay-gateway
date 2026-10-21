#!/usr/bin/env sh
# Sincroniza o repositório no servidor com origin/$BRANCH (deploy).
# Uso: sh docker/git-sync-for-deploy.sh <INSTALL_DIR> <BRANCH> [sudo_cmd]
#
# Preserva .docker/stack.env em volta de reset --hard. Se o update.sh já envolveu
# o sync (GETFY_STACK_ENV_PRESERVE_OWNED=1), só faz o Git.
set -eu

INSTALL_DIR="${1:?INSTALL_DIR}"
BRANCH="${2:?BRANCH}"
SUDO="${3:-}"

GIT_BASE="git -c safe.directory=$INSTALL_DIR -C $INSTALL_DIR"

SELF_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
PRESERVE_SH=""
if [ -f "$SELF_DIR/preserve-local-stack-env.sh" ]; then
  PRESERVE_SH="$SELF_DIR/preserve-local-stack-env.sh"
elif [ -f "$INSTALL_DIR/docker/preserve-local-stack-env.sh" ]; then
  PRESERVE_SH="$INSTALL_DIR/docker/preserve-local-stack-env.sh"
fi

if [ "${GETFY_STACK_ENV_PRESERVE_OWNED:-0}" != "1" ]; then
  PRESERVE_DIR="${GETFY_STACK_ENV_PRESERVE_DIR:-}"
  if [ -z "$PRESERVE_DIR" ]; then
    PRESERVE_DIR="$(mktemp -d "${TMPDIR:-/tmp}/getfy-stack-env.XXXXXX")" || exit 1
    chmod 700 "$PRESERVE_DIR" || exit 1
  fi
  if [ -n "$PRESERVE_SH" ]; then
    # Reentra neste script com OWNED=1 dentro do wrap (trap + restore).
    export GETFY_REPO_URL="${GETFY_REPO_URL:-https://github.com/stacker-builders/stacker-gateway.git}"
    exec sh "$PRESERVE_SH" wrap "$INSTALL_DIR" "$PRESERVE_DIR" -- \
      env GETFY_STACK_ENV_PRESERVE_OWNED=1 GETFY_REPO_URL="$GETFY_REPO_URL" \
        sh "$0" "$INSTALL_DIR" "$BRANCH" "$SUDO"
  fi
  mkdir -p "$PRESERVE_DIR/.docker" || exit 1
  if [ -f "$INSTALL_DIR/.docker/stack.env" ]; then
    # shellcheck disable=SC2086
    if ! $SUDO cp -p "$INSTALL_DIR/.docker/stack.env" "$PRESERVE_DIR/.docker/stack.env"; then
      echo "[git-sync] ERRO: backup de stack.env falhou — abortando." >&2
      echo "[git-sync] Diretório de backup (protegido): $PRESERVE_DIR" >&2
      exit 1
    fi
    if [ ! -f "$PRESERVE_DIR/.docker/stack.env" ]; then
      echo "[git-sync] ERRO: backup de stack.env falhou — abortando." >&2
      echo "[git-sync] Diretório de backup (protegido): $PRESERVE_DIR" >&2
      exit 1
    fi
  fi
  restored=0
  preserve_restore_inline() {
    if [ "$restored" = "1" ]; then
      return 0
    fi
    if [ -f "$PRESERVE_DIR/.docker/stack.env" ]; then
      # shellcheck disable=SC2086
      if ! $SUDO mkdir -p "$INSTALL_DIR/.docker"; then
        echo "[git-sync] ERRO: restauração falhou. Backup protegido em: $PRESERVE_DIR" >&2
        return 1
      fi
      # shellcheck disable=SC2086
      if ! $SUDO cp -p "$PRESERVE_DIR/.docker/stack.env" "$INSTALL_DIR/.docker/stack.env"; then
        echo "[git-sync] ERRO: restauração falhou. Backup protegido em: $PRESERVE_DIR" >&2
        return 1
      fi
      if [ ! -f "$INSTALL_DIR/.docker/stack.env" ] \
        || ! cmp -s "$PRESERVE_DIR/.docker/stack.env" "$INSTALL_DIR/.docker/stack.env"; then
        echo "[git-sync] ERRO: restauração falhou. Backup protegido em: $PRESERVE_DIR" >&2
        return 1
      fi
    fi
    rm -rf "$PRESERVE_DIR"
    restored=1
    return 0
  }
  on_exit() {
    rc=$?
    trap - EXIT INT TERM
    if preserve_restore_inline; then
      exit "$rc"
    fi
    echo "[git-sync] ERRO: restauração falhou. Backup protegido em: $PRESERVE_DIR" >&2
    if [ "$rc" -ne 0 ]; then
      exit "$rc"
    fi
    exit 1
  }
  trap on_exit EXIT INT TERM
fi

# shellcheck disable=SC2086
$SUDO $GIT_BASE remote set-url origin "${GETFY_REPO_URL:-https://github.com/stacker-builders/stacker-gateway.git}" >/dev/null 2>&1 || true

if [ -f "$INSTALL_DIR/.git/MERGE_HEAD" ] || [ -f "$INSTALL_DIR/.git/REBASE_HEAD" ]; then
  echo "Aviso: merge/rebase anterior incompleto — abortando." >&2
  # shellcheck disable=SC2086
  $SUDO $GIT_BASE merge --abort >/dev/null 2>&1 || true
  # shellcheck disable=SC2086
  $SUDO $GIT_BASE rebase --abort >/dev/null 2>&1 || true
fi

# shellcheck disable=SC2086
$SUDO rm -rf "$INSTALL_DIR/public/build"

UNMERGED=""
# shellcheck disable=SC2086
UNMERGED=$($SUDO $GIT_BASE diff --name-only --diff-filter=U 2>/dev/null || true)
if [ -n "$UNMERGED" ]; then
  echo "Aviso: índice Git com ficheiros por resolver — a repor estado limpo." >&2
  # shellcheck disable=SC2086
  $SUDO $GIT_BASE reset --hard HEAD 2>/dev/null || true
fi

# shellcheck disable=SC2086
$SUDO $GIT_BASE fetch --all --prune

# shellcheck disable=SC2086
$SUDO $GIT_BASE reset --hard "origin/$BRANCH"

# shellcheck disable=SC2086
$SUDO $GIT_BASE checkout -B "$BRANCH" "origin/$BRANCH" 2>/dev/null || true
