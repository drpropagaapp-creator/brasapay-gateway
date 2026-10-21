#!/usr/bin/env sh
# Fixture: git-sync antigo (reset --hard SEM preservar stack.env).
# Usado só nos testes do primeiro update de uma instalação antiga.
set -eu

INSTALL_DIR="${1:?INSTALL_DIR}"
BRANCH="${2:?BRANCH}"
SUDO="${3:-}"

GIT_BASE="git -c safe.directory=$INSTALL_DIR -C $INSTALL_DIR"

# shellcheck disable=SC2086
$SUDO $GIT_BASE remote set-url origin "${GETFY_REPO_URL:-https://github.com/stacker-builders/stacker-gateway.git}" >/dev/null 2>&1 || true

if [ -f "$INSTALL_DIR/.git/MERGE_HEAD" ] || [ -f "$INSTALL_DIR/.git/REBASE_HEAD" ]; then
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
  # shellcheck disable=SC2086
  $SUDO $GIT_BASE reset --hard HEAD 2>/dev/null || true
fi

# shellcheck disable=SC2086
$SUDO $GIT_BASE fetch --all --prune
# shellcheck disable=SC2086
$SUDO $GIT_BASE reset --hard "origin/$BRANCH"
# shellcheck disable=SC2086
$SUDO $GIT_BASE checkout -B "$BRANCH" "origin/$BRANCH" 2>/dev/null || true
