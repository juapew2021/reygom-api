FROM php:8.2-cli-alpine

# PostgreSQL + procesamiento de imágenes con soporte JPEG, PNG y WebP
RUN apk add --no-cache \
        postgresql-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        libwebp-dev \
        freetype-dev \
        curl-dev \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        gd \
        curl
WORKDIR /var/www/html

COPY . .

# Configuración de subida de archivos
RUN printf '%s\n' \
    'file_uploads=On' \
    'upload_max_filesize=15M' \
    'post_max_size=80M' \
    'max_file_uploads=20' \
    'memory_limit=256M' \
    'max_execution_time=120' \
    > /usr/local/etc/php/conf.d/reygom-uploads.ini

# Carpeta persistente para fotografías
RUN mkdir -p /var/www/media/properties \
    && chown -R www-data:www-data /var/www/media

EXPOSE 8000

CMD ["php", "-S", "0.0.0.0:8000", "index.php"]
# Preserve the geolocation reader, attribution and database in clean builds.
COPY runtime/analytics /opt/analytics

# Monthly public GeoIP database, verified against the reviewed artifact.
RUN wget -q -O /tmp/dbip.mmdb.gz https://download.db-ip.com/free/dbip-city-lite-2026-10.mmdb.gz \
    && gzip -dc /tmp/dbip.mmdb.gz > /opt/analytics/dbip-city-lite.mmdb \
    && echo "9e250f02722d1ad1780f88192f0e908af285478a33e76b88b349c9181ae9d0f5  /opt/analytics/dbip-city-lite.mmdb" | sha256sum -c - \
    && rm /tmp/dbip.mmdb.gz
