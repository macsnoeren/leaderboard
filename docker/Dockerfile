# Testomgeving: Apache + PHP (webapp). De AI-agent draait in een eigen container (Dockerfile.agent).
# Bouwen vanuit de repo-root: docker compose -f docker/docker-compose.yml up --build
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends sqlite3 \
 && rm -rf /var/lib/apt/lists/*

# Ontwikkel-instellingen (foutmeldingen zichtbaar) en ruimte voor archieven (import/restore): een export van een
# spel met de maximale 2 GB aan bijlagen moet erin passen. Bijlagen gaan in stukken van 8 MB en hebben dit niet nodig.
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
 && printf 'upload_max_filesize=3G\npost_max_size=3G\nmax_execution_time=300\nmax_input_time=900\n' > "$PHP_INI_DIR/conf.d/uploads.ini"

# conf/ blijft buiten de webroot, net als in productie
ENV APACHE_DOCUMENT_ROOT=/var/www/leaderboard/htdocs
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Bijlagen alleen via PHP (zie htdocs/.htaccess; die leest Apache hier niet, AllowOverride None)
RUN printf '<Directory ${APACHE_DOCUMENT_ROOT}/artifacts>\n    Require all denied\n</Directory>\n' > /etc/apache2/conf-available/leaderboard-artifacts.conf \
 && a2enconf leaderboard-artifacts

# Apache laat standaard maximaal 1 GB per request toe; gelijk aan post_max_size hierboven (3 GB)
RUN printf 'LimitRequestBody 3221225472\n' > /etc/apache2/conf-available/leaderboard-uploads.conf \
 && a2enconf leaderboard-uploads

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
