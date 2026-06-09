FROM php:8.2-cli-alpine

# Instalar dependencias para PostgreSQL
RUN apk add --no-cache postgresql-dev \
    && docker-php-ext-install pdo pdo_pgsql

# Directorio de trabajo
WORKDIR /var/www/html

# Copiar el código
COPY index.php .

# Exponer el puerto 8000
EXPOSE 8000

# Iniciar el servidor integrado de PHP (ideal para APIs ligeras/microservicios)
CMD ["php", "-S", "0.0.0.0:8000", "index.php"]
