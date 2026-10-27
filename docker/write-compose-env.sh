#!/usr/bin/env sh
# Gera .docker/stack.compose.env a partir de .docker/stack.env.
# Compose interpola $VAR nos --env-file: um cifrão na senha vira variável vazia
# e o container PHP recebe senha truncada. Duplicar $ → $$ preserva o literal.
# stack.env canônico NÃO é alterado (fonte principal, senha real).
#
# Uso: COMPOSE_ENV="$(sh docker/write-compose-env.sh)"
#      docker compose --env-file "$COMPOSE_ENV" ...
# Não imprime valores. Exit 1 se stack.env ausente.
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

SRC="${GETFY_STACK_ENV_FILE:-.docker/stack.env}"
DST="${GETFY_COMPOSE_ENV_FILE:-.docker/stack.compose.env}"

if [ ! -f "$SRC" ]; then
  echo "[compose-env] ERRO: $SRC ausente." >&2
  exit 1
fi

mkdir -p "$(dirname "$DST")"
TMP="$(mktemp)"
# shellcheck disable=SC2064
trap 'rm -f "$TMP"' EXIT INT TERM

while IFS= read -r line || [ -n "$line" ]; do
  line="$(printf '%s' "$line" | tr -d '\r')"
  case "$line" in
    ''|\#*)
      printf '%s\n' "$line"
      ;;
    *=*)
      key="${line%%=*}"
      val="${line#*=}"
      escaped="$(printf '%s' "$val" | awk '{ gsub(/\$/, "$$"); print }')"
      printf '%s=%s\n' "$key" "$escaped"
      ;;
    *)
      printf '%s\n' "$line"
      ;;
  esac
done < "$SRC" > "$TMP"

mv "$TMP" "$DST"
trap - EXIT INT TERM
chmod 600 "$DST" 2>/dev/null || true
printf '%s\n' "$DST"
