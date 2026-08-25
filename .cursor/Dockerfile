# Cloud Agent base image for the TSO Admin Notices Manager WordPress plugin.
# Provides the PHP/MariaDB toolchain plus WordPress.org tooling (WP-CLI, Composer,
# PHP_CodeSniffer + WordPress Coding Standards, Subversion) used for development,
# the TSO "definition of done" gates, and .org releases.
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1

# System packages: PHP 8.3 CLI + the extensions WordPress needs, MariaDB server/client,
# and release/build tooling. git + curl are required in fully custom images.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        less \
        unzip \
        subversion \
        php-cli \
        php-mysql \
        php-gd \
        php-curl \
        php-mbstring \
        php-xml \
        php-zip \
        php-intl \
        php-bcmath \
        php-sqlite3 \
        mariadb-server \
        mariadb-client \
    && rm -rf /var/lib/apt/lists/*

# WP-CLI (official stable build).
RUN curl -fsSL -o /usr/local/bin/wp \
        https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

# Composer.
RUN curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet \
    && rm -f /tmp/composer-setup.php

# PHP_CodeSniffer + WordPress Coding Standards + PHPCompatibility, installed to a
# shared location and exposed on PATH so any user can run `phpcs`/`phpcbf`.
ENV COMPOSER_HOME=/opt/composer
RUN composer global config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true \
    && composer global require --no-interaction --no-progress \
        squizlabs/php_codesniffer:^3.9 \
        wp-coding-standards/wpcs:^3.1 \
        phpcompatibility/phpcompatibility-wp:^2.1 \
        dealerdirect/phpcodesniffer-composer-installer:^1.0 \
    && ln -sf /opt/composer/vendor/bin/phpcs /usr/local/bin/phpcs \
    && ln -sf /opt/composer/vendor/bin/phpcbf /usr/local/bin/phpcbf \
    && chmod -R a+rX /opt/composer

# The Cloud Agent runs as the pre-existing "ubuntu" user (uid 1000).
USER ubuntu
