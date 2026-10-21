#!/usr/bin/env sh
# Backup/restore de stack.env e overrides locais em volta de git reset/checkout.
# Não imprime valores. Preserva modo (chmod).
#
# Uso:
#   sh docker/preserve-local-stack-env.sh backup  <INSTALL_DIR> [DEST_DIR]
#   sh docker/preserve-local-stack-env.sh restore <INSTALL_DIR> [DEST_DIR]
#
# DEST_DIR default: INSTALL_DIR/.docker/.git-preserve (gitignored, fora do reset).
set -eu

ACTION="${1:-}"
INSTALL_DIR="${2:-}"
DEST_DIR="${3:-}"

if [ -z "$ACTION" ] || [ -z "$INSTALL_DIR" ]; then
  echo "uso: preserve-local-stack-env.sh backup|restore <INSTALL_DIR> [DEST_DIR]" >&2
  exit 1
fi

case "$INSTALL_DIR" in
  /*) ;;
  *) INSTALL_DIR="$(CDPATH= cd -- "$INSTALL_DIR" && pwd)" ;;
esac

if [ -z "$DEST_DIR" ]; then
  DEST_DIR="$INSTALL_DIR/.docker/.git-preserve"
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
  chmod "$(file_mode "$src")" "$dest" 2>/dev/null || chmod 600 "$dest"
  printf '%s\n' "$(file_mode "$src")" > "$dest.mode"
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
}

backup_all() {
  mkdir -p "$BACKUP_DOCKER"
  chmod 700 "$DEST_DIR" 2>/dev/null || true
  chmod 700 "$BACKUP_DOCKER" 2>/dev/null || true
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
  echo "[preserve] backup local (.docker/stack.env e overrides) concluído." >&2
}

restore_all() {
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

case "$ACTION" in
  backup) backup_all ;;
  restore) restore_all ;;
  *)
    echo "ação inválida: $ACTION (use backup|restore)" >&2
    exit 1
    ;;
esac
