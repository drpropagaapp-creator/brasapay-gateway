#!/usr/bin/env bash
# Remove imagens Docker órfãs após rebuild/update remoto.
# Chamado por update.sh e por docker/stacker-apply-update.sh.
# Nunca mexe em volumes nem containers em execução.
set -euo pipefail

if [ "${GETFY_SKIP_DOCKER_PRUNE:-0}" = "1" ]; then
  echo "Limpeza Docker ignorada (GETFY_SKIP_DOCKER_PRUNE=1)."
  exit 0
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "docker não encontrado." >&2
  exit 1
fi

show_images_line() {
  docker system df 2>/dev/null | awk '
    $1 == "Images" {
      printf "  Images: %s total, %s reclaimable\n", $4, $5
      exit
    }
  ' || true
}

echo "Antes:"
show_images_line
docker system df 2>/dev/null | head -4 || true

echo ""
echo "Removendo imagens dangling (sem tag)..."
docker image prune -f || true

echo ""
echo "Removendo cache de build com mais de 14 dias..."
docker builder prune -f --filter until=336h 2>/dev/null || true

# Nunca prune unused-all: apaga tags de rollback/candidata sem container.
# No apply remoto o padrão é 1 (GETFY_DOCKER_PRUNE_UNUSED=1); no update.sh legado costuma ser 0.
if [ "${GETFY_DOCKER_PRUNE_UNUSED:-0}" = "1" ]; then
  echo ""
  echo "Removendo imagens não usadas (exceto rollback/candidata/latest)..."
  docker images --format '{{.Repository}}:{{.Tag}}' | while IFS= read -r img; do
    [ -n "$img" ] || continue
    case "$img" in
      *:'<none>'|'<none>:<none>') continue ;;
      *:rollback*|*:*-rollback*|*-rollback-*) echo "  preservando $img"; continue ;;
      getfy_app:rollback-app-pre-php836|getfy_app:rollback-queue-pre-php836) echo "  preservando $img"; continue ;;
      *:candidate*|*:*-candidate*|*-candidate*) echo "  preservando $img"; continue ;;
      getfy_app:latest|getfy_app:dev|stacker_agent:latest|stacker_agent:dev) echo "  preservando $img"; continue ;;
    esac
    if docker ps -a --format '{{.Image}}' | grep -Fxq "$img"; then
      continue
    fi
    echo "  removendo $img"
    docker image rm "$img" 2>/dev/null || true
  done
fi

echo ""
echo "Depois:"
show_images_line
