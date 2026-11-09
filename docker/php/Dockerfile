# Keep the PHP patch and Alpine minor explicit; update this with Dockerfile.prod.
FROM php:8.3.36-fpm-alpine3.24

# System deps
RUN apk add --no-cache \
        bash \
        git \
        curl \
        ca-certificates \
        openjdk21-jre-headless \
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

# Pin the standalone PDFtk Java build and verify its digest. The wrapper is a
# minimal argv-preserving shim so Symfony Process never interpolates a shell.
RUN mkdir -p /usr/local/lib/pdftk \
    && curl --fail --location --silent --show-error \
        --output /usr/local/lib/pdftk/pdftk-all.jar \
        https://gitlab.com/pdftk-java/pdftk/-/jobs/1527259628/artifacts/raw/build/libs/pdftk-all.jar \
    && echo '2f625bd6e2df0ff8cab0d97bb769746a421879179c25c22646bd8460b5f69b00  /usr/local/lib/pdftk/pdftk-all.jar' | sha256sum -c - \
    && printf '%s\n' '#!/bin/sh' 'exec java -jar /usr/local/lib/pdftk/pdftk-all.jar "$@"' > /usr/local/bin/pdftk \
    && chmod 0755 /usr/local/bin/pdftk \
    && pdftk --version

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

# The upstream FPM pool defaults to five workers, which saturated during
# multi-role realtime flows. The ten-worker dev ceiling also saturated: Nginx
# recorded 2.3–5.0 s FastCGI response times and PHP-FPM logged max_children=10.
# The active host had 9.9 GiB available and dev workers used about 60 MiB RSS;
# allow sixteen concurrent requests while keeping the dynamic idle pool small.
# Production uses its separate, independently bounded image configuration.
RUN sed -i \
        -e 's/^pm.max_children = 5$/pm.max_children = 16/' \
        -e 's/^pm.min_spare_servers = 1$/pm.min_spare_servers = 2/' \
        -e 's/^pm.max_spare_servers = 3$/pm.max_spare_servers = 5/' \
        /usr/local/etc/php-fpm.d/www.conf \
    && grep -q '^pm.max_children = 16$' /usr/local/etc/php-fpm.d/www.conf \
    && grep -q '^pm.min_spare_servers = 2$' /usr/local/etc/php-fpm.d/www.conf \
    && grep -q '^pm.max_spare_servers = 5$' /usr/local/etc/php-fpm.d/www.conf

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Non-root user mirroring host UID where reasonable
ARG UID=1000
ARG GID=1000
RUN addgroup -g ${GID} -S www && adduser -u ${UID} -S www -G www

COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/zz-uploads.ini

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
