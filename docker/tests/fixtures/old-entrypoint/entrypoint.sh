#!/bin/sh
# Simula o entrypoint em produção: GETFY_SKIP_MIGRATE não existe.
# Só GETFY_RUN_SETUP=false evita migrate. Não imprime segredos.
set -eu
if [ "${GETFY_RUN_SETUP:-true}" = "true" ]; then
  echo "entrypoint antigo: migrate --force (GETFY_SKIP_MIGRATE ignorado)"
  echo migrated > /tmp/migrate-ran
fi
exec "$@"
