#!/usr/bin/env sh
# Backup/restore de stack.env e overrides locais em volta de git reset/checkout.
# Não imprime valores. Preserva modo (chmod). Não ignora falhas.
#
# Uso:
#   sh docker/preserve-local-stack-env.sh backup  <INSTALL_DIR> [DEST_DIR]
#   sh docker/preserve-local-stack-env.sh restore <INSTALL_DIR> [DEST_DIR]
#   sh docker/preserve-local-stack-env.sh wrap    <INSTALL_DIR> [DEST_DIR] -- <comando...>
#
# wrap: aborta se o backup falhar; restaura em EXIT/INT/TERM; preserva o rc do
# comando; só apaga o backup após restauração confirmada.
set -eu

ACTION="${1:-}"
INSTALL_DIR="${2:-}"
DEST_DIR="${3:-}"

if [ -z "$ACTION" ] || [ -z "$INSTALL_DIR" ]; then
  echo "uso: preserve-local-stack-env.sh backup|restore|wrap <INSTALL_DIR> [DEST_DIR] [-- cmd]" >&2
  exit 1
fi

case "$INSTALL_DIR" in
  /*) ;;
  *) INSTALL_DIR="$(CDPATH= cd -- "$INSTALL_DIR" && pwd)" ;;
esac

if [ -z "$DEST_DIR" ] || [ "$DEST_DIR" = "--" ]; then
  DEST_DIR="${GETFY_STACK_ENV_PRESERVE_DIR:-$INSTALL_DIR/.docker/.git-preserve}"
fi

DOCKER_DIR="$INSTALL_DIR/.docker"
BACKUP_DOCKER="$DEST_DIR/.docker"

file_mode() {
  stat -c '%a' "$1" 2>/dev/null || stat -f '%OLp' "$1" 2>/dev/null || echo 600
}

copy_preserved() {
  src="$1"
  dest="$2"
  [ -f "$src" ] || return 0
  mkdir -p "$(dirname "$dest")"
  cp -p "$src" "$dest"
  orig_mode="$(file_mode "$src")"
  chmod "$orig_mode" "$dest" 2>/dev/null || chmod 600 "$dest"
  printf '%s\n' "$orig_mode" > "$dest.mode"
  if [ ! -f "$dest" ]; then
    echo "[preserve] ERRO: backup não gravou $(basename "$src")." >&2
    return 1
  fi
}

restore_one() {
  src="$1"
  dest="$2"
  [ -f "$src" ] || return 0
  mkdir -p "$(dirname "$dest")"
  cp -p "$src" "$dest"
  if [ -f "$src.mode" ]; then
    chmod "$(tr -d ' \t\r\n' < "$src.mode")" "$dest" 2>/dev/null || chmod 600 "$dest"
  else
    chmod "$(file_mode "$src")" "$dest" 2>/dev/null || chmod 600 "$dest"
  fi
  if ! cmp -s "$src" "$dest"; then
    echo "[preserve] ERRO: restauração não conferiu $(basename "$src") (valores não exibidos)." >&2
    return 1
  fi
}

backup_all() {
  if [ "${GETFY_PRESERVE_FAIL_BACKUP:-0}" = "1" ]; then
    echo "[preserve] ERRO: backup forçado a falhar (teste)." >&2
    return 1
  fi
  mkdir -p "$BACKUP_DOCKER"
  chmod 700 "$DEST_DIR"
  chmod 700 "$BACKUP_DOCKER"
  if [ ! -d "$DOCKER_DIR" ]; then
    return 0
  fi
  if [ -f "$DOCKER_DIR/stack.env" ]; then
    copy_preserved "$DOCKER_DIR/stack.env" "$BACKUP_DOCKER/stack.env"
  fi
  if [ -f "$DOCKER_DIR/stack.compose.env" ]; then
    copy_preserved "$DOCKER_DIR/stack.compose.env" "$BACKUP_DOCKER/stack.compose.env"
  fi
  if [ -f "$DOCKER_DIR/compose-profile" ]; then
    copy_preserved "$DOCKER_DIR/compose-profile" "$BACKUP_DOCKER/compose-profile"
  fi
  for f in "$DOCKER_DIR"/*.override.yml "$DOCKER_DIR"/*.override.json "$DOCKER_DIR"/php836*; do
    [ -f "$f" ] || continue
    case "$(basename "$f")" in
      *.mode) continue ;;
    esac
    copy_preserved "$f" "$BACKUP_DOCKER/$(basename "$f")"
  done
  if [ -f "$DOCKER_DIR/stack.env" ] && [ ! -f "$BACKUP_DOCKER/stack.env" ]; then
    echo "[preserve] ERRO: backup de stack.env falhou." >&2
    return 1
  fi
  echo "[preserve] backup local (.docker/stack.env e overrides) concluído." >&2
}

restore_all() {
  if [ "${GETFY_PRESERVE_FAIL_RESTORE:-0}" = "1" ]; then
    echo "[preserve] ERRO: restauração forçada a falhar (teste)." >&2
    return 1
  fi
  if [ ! -d "$BACKUP_DOCKER" ]; then
    echo "[preserve] nenhum backup para restaurar." >&2
    return 0
  fi
  mkdir -p "$DOCKER_DIR"
  for f in "$BACKUP_DOCKER"/*; do
    [ -f "$f" ] || continue
    case "$f" in
      *.mode) continue ;;
    esac
    restore_one "$f" "$DOCKER_DIR/$(basename "$f")"
  done
  echo "[preserve] stack.env/overrides restaurados (valores não exibidos)." >&2
}

finish_restore_ok() {
  rm -rf "$DEST_DIR"
}

report_restore_fail() {
  echo "[preserve] ERRO: restauração falhou. Backup protegido em: $DEST_DIR" >&2
  echo "[preserve] Não apaguei o backup. Não imprimo valores." >&2
}

run_wrap() {
  shift 2
  if [ "${1:-}" = "--" ]; then
    shift
  elif [ -n "${1:-}" ] && [ "${1:-}" != "--" ]; then
    DEST_DIR="$1"
    BACKUP_DOCKER="$DEST_DIR/.docker"
    shift
    if [ "${1:-}" = "--" ]; then
      shift
    fi
  fi
  if [ "$#" -eq 0 ]; then
    echo "uso: preserve-local-stack-env.sh wrap <INSTALL_DIR> [DEST_DIR] -- <comando>" >&2
    exit 1
  fi
  if ! backup_all; then
    echo "[preserve] ERRO: backup falhou — comando não será executado." >&2
    echo "[preserve] Diretório de backup (protegido): $DEST_DIR" >&2
    exit 1
  fi
  restored=0
  do_restore() {
    if [ "$restored" = "1" ]; then
      return 0
    fi
    if restore_all; then
      restored=1
      finish_restore_ok
      return 0
    fi
    report_restore_fail
    return 1
  }
  wrap_rc=0
  on_signal_exit() {
    wrap_rc=$?
    trap - EXIT INT TERM
    if do_restore; then
      exit "$wrap_rc"
    fi
    if [ "$wrap_rc" -ne 0 ]; then
      exit "$wrap_rc"
    fi
    exit 1
  }
  trap on_signal_exit EXIT INT TERM
  set +e
  "$@"
  wrap_rc=$?
  set -e
  trap - EXIT INT TERM
  if do_restore; then
    exit "$wrap_rc"
  fi
  if [ "$wrap_rc" -ne 0 ]; then
    exit "$wrap_rc"
  fi
  exit 1
}

case "$ACTION" in
  backup) backup_all ;;
  restore) restore_all ;;
  wrap) run_wrap "$@" ;;
  *)
    echo "ação inválida: $ACTION (use backup|restore|wrap)" >&2
    exit 1
    ;;
esac
