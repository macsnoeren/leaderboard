# Testomgeving: Apache + PHP (webapp) en de Python AI-agent in één container.
# Bouwen vanuit de repo-root: docker compose -f docker/docker-compose.yml up --build
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends python3 python3-requests sqlite3 \
 && rm -rf /var/lib/apt/lists/*

# Ontwikkel-instellingen (foutmeldingen zichtbaar) en ruimte voor artifact-uploads
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
 && printf 'upload_max_filesize=64M\npost_max_size=64M\n' > "$PHP_INI_DIR/conf.d/uploads.ini"

# conf/ blijft buiten de webroot, net als in productie
ENV APACHE_DOCUMENT_ROOT=/var/www/leaderboard/htdocs
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/leaderboard
COPY htdocs htdocs
COPY conf conf
COPY bin bin
COPY lib lib
COPY agentic-service agentic-service

# PHPMailer ophalen als de submodule lokaal niet geïnitialiseerd is
RUN [ -f lib/PHPMailer/src/PHPMailer.php ] || ( \
      mkdir -p lib/PHPMailer \
   && curl -fsSL https://github.com/PHPMailer/PHPMailer/archive/b17101946bd983ba156aa587073360f559fbeb4a.tar.gz \
      | tar xz --strip-components=1 -C lib/PHPMailer )

COPY docker/entrypoint.sh /usr/local/bin/leaderboard-entrypoint
RUN chmod +x /usr/local/bin/leaderboard-entrypoint

ENTRYPOINT ["leaderboard-entrypoint"]
