# Testomgeving: Apache + PHP (webapp). De AI-agent draait in een eigen container (Dockerfile.agent).
# Bouwen vanuit de repo-root: docker compose -f docker/docker-compose.yml up --build
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends sqlite3 \
 && rm -rf /var/lib/apt/lists/*

# Ontwikkel-instellingen (foutmeldingen zichtbaar) en ruimte voor uploads (bijlagen, en archieven voor import/restore)
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
 && printf 'upload_max_filesize=512M\npost_max_size=512M\nmax_execution_time=300\n' > "$PHP_INI_DIR/conf.d/uploads.ini"

# conf/ blijft buiten de webroot, net als in productie
ENV APACHE_DOCUMENT_ROOT=/var/www/leaderboard/htdocs
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Bijlagen alleen via PHP (zie htdocs/.htaccess; die leest Apache hier niet, AllowOverride None)
RUN printf '<Directory ${APACHE_DOCUMENT_ROOT}/artifacts>\n    Require all denied\n</Directory>\n' > /etc/apache2/conf-available/leaderboard-artifacts.conf \
 && a2enconf leaderboard-artifacts

WORKDIR /var/www/leaderboard
COPY htdocs htdocs
COPY conf conf
COPY bin bin
COPY lib lib

# PHPMailer ophalen als de submodule lokaal niet geïnitialiseerd is
RUN [ -f lib/PHPMailer/src/PHPMailer.php ] || ( \
      mkdir -p lib/PHPMailer \
   && curl -fsSL https://github.com/PHPMailer/PHPMailer/archive/b17101946bd983ba156aa587073360f559fbeb4a.tar.gz \
      | tar xz --strip-components=1 -C lib/PHPMailer )

COPY docker/entrypoint.sh /usr/local/bin/leaderboard-entrypoint
RUN chmod +x /usr/local/bin/leaderboard-entrypoint

ENTRYPOINT ["leaderboard-entrypoint"]
