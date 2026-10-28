#!/bin/sh
# Servidor web do container "app".
#   GETFY_WEB_SERVER=fpm     (padrão) nginx + PHP-FPM
#   GETFY_WEB_SERVER=artisan  volta ao php artisan serve (rollback sem rebuild: mudar no stack.env e reiniciar o app)
set -e

if [ "${GETFY_WEB_SERVER:-fpm}" = "artisan" ]; then
  exec php artisan serve --host=0.0.0.0 --port="${PORT:-80}" --no-reload
fi

if ! command -v php-fpm >/dev/null 2>&1 || ! command -v nginx >/dev/null 2>&1; then
  echo "php-fpm/nginx ausentes nesta imagem — usando php artisan serve." >&2
  exec php artisan serve --host=0.0.0.0 --port="${PORT:-80}" --no-reload
fi

nginx -t -q

# -R: o pool roda como root (ver docker/php-fpm/zz-getfy.conf).
php-fpm -R &
FPM_PID=$!
nginx -g 'daemon off;' &
NGINX_PID=$!

stop() {
  kill -QUIT "$NGINX_PID" 2>/dev/null || true
  kill -QUIT "$FPM_PID" 2>/dev/null || true
  wait "$NGINX_PID" 2>/dev/null || true
  wait "$FPM_PID" 2>/dev/null || true
  exit 0
}
trap stop TERM INT QUIT

# Se um dos dois cair, encerra o container para o Docker reiniciar (restart: unless-stopped).
while kill -0 "$FPM_PID" 2>/dev/null && kill -0 "$NGINX_PID" 2>/dev/null; do
  sleep 5
done
echo "php-fpm ou nginx parou — encerrando para o Docker reiniciar o container." >&2
kill -TERM "$NGINX_PID" "$FPM_PID" 2>/dev/null || true
exit 1
