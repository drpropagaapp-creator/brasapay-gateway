# Imagem de teste: PHP 8.3.36 + PDO pgsql + entrypoint antigo (sem GETFY_SKIP_MIGRATE).
FROM php:8.3.36-cli-alpine
RUN apk add --no-cache postgresql-dev \
    && docker-php-ext-install pdo_pgsql
COPY entrypoint.sh /usr/local/bin/docker-entrypoint-old.sh
RUN chmod +x /usr/local/bin/docker-entrypoint-old.sh \
    && mkdir -p /var/www/html \
    && printf '%s\n' '<?php echo "ok";' > /var/www/html/index.php
WORKDIR /var/www/html
ENTRYPOINT ["/usr/local/bin/docker-entrypoint-old.sh"]
CMD ["php", "-S", "0.0.0.0:80", "-t", "/var/www/html"]
