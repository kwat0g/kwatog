FROM php:8.3-fpm-alpine

# System deps
RUN apk add --no-cache \
        bash \
        git \
        curl \
        zip \
        unzip \
        icu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        oniguruma-dev \
        postgresql-dev \
        autoconf \
        g++ \
        make \
        linux-headers

# PHP core extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        pgsql \
        gd \
        zip \
        bcmath \
        intl \
        exif \
        opcache \
        pcntl

# Redis (PECL)
RUN pecl install redis \
    && docker-php-ext-enable redis

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Non-root user mirroring host UID where reasonable
ARG UID=1000
ARG GID=1000
RUN addgroup -g ${GID} -S www && adduser -u ${UID} -S www -G www

WORKDIR /var/www
RUN chown -R www:www /var/www
USER www

EXPOSE 9000
CMD ["php-fpm"]
