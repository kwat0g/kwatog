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
        # Pinned to the server's major. `postgresql-client` tracks Alpine's
        # newest major (18 on 3.23), and a v18 pg_dump emits `transaction_timeout`
        # / `\restrict`, which a 16 server refuses on restore — while `gzip -t`
        # still passes, so the artifact looks verified. Bump with the db image.
        postgresql16-client \
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

# The development compose file mounts the repository's API source at
# /var/www, so mount these helpers there as well for local backup/restore
# commands. They are also copied into the image for non-mounted usage.
COPY scripts/db-backup.sh /var/www/scripts/db-backup.sh
COPY scripts/db-restore.sh /var/www/scripts/db-restore.sh
COPY scripts/files-backup.sh /var/www/scripts/files-backup.sh
COPY scripts/files-restore.sh /var/www/scripts/files-restore.sh

RUN chmod +x /var/www/scripts/db-backup.sh /var/www/scripts/db-restore.sh \
        /var/www/scripts/files-backup.sh /var/www/scripts/files-restore.sh

RUN chown -R www:www /var/www
USER www

EXPOSE 9000
CMD ["php-fpm"]
