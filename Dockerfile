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