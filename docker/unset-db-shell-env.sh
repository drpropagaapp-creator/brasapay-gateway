#!/usr/bin/env sh
# Remove da SHELL as variáveis que o Compose interpolaria por cima do --env-file.
#
# NÃO use `unset GETFY_DB_*` — o glob do shell expande nomes de *arquivo*,
# não variáveis de ambiente.
# Prefixo GETFY_DB_* (todas as chaves que batem). Laravel DB_* só as seis
# conhecidas — nunca DBUS_* nem outras DB_*.
#
# Uso:
#   . docker/unset-db-shell-env.sh
#   unset_db_shell_env
#   # para sudo/env: env $(db_env_u_flags) comando

db_env_names() {
  env | awk -F= '
    $1 ~ /^GETFY_DB_[A-Za-z0-9_]+$/ { print $1 }
    $1 ~ /^DB_(CONNECTION|HOST|PORT|DATABASE|USERNAME|PASSWORD)$/ { print $1 }
  '
}

db_env_u_flags() {
  db_env_names | while IFS= read -r name; do
    [ -n "$name" ] || continue
    printf ' -u %s' "$name"
  done
}

unset_db_shell_env() {
  names="$(db_env_names || true)"
  if [ -z "$names" ]; then
    return 0
  fi
  # shellcheck disable=SC2086
  unset $names 2>/dev/null || true
}
