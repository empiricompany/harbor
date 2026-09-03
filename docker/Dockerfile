ARG PHP_VERSION=8.4
ARG DEBIAN_VARIANT=trixie
ARG HARBOR_PHP_EXTENSIONS=""
FROM dunglas/frankenphp:php${PHP_VERSION}-${DEBIAN_VARIANT}

ARG MAHO_UID=1000
ARG MAHO_GID=1000
ARG HARBOR_PHP_EXTENSIONS

RUN groupadd --gid "${MAHO_GID}" maho \
    && useradd --uid "${MAHO_UID}" --gid "${MAHO_GID}" --create-home --shell /bin/bash maho
RUN install -d /data /config \
    && chown -R maho:maho /data /config

RUN set -eux; \
    APT_PKGS="git patch unzip curl msmtp default-mysql-client postgresql-client sqlite3"; \
    PHP_EXTENSIONS="ftp gd intl zip soap pcntl pdo_mysql pdo_pgsql pgsql pdo_sqlite redis xdebug $HARBOR_PHP_EXTENSIONS"; \
    install-php-extensions $PHP_EXTENSIONS \
    && apt-get update \
    && apt-get install -y --no-install-recommends $APT_PKGS \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /var/log/* /var/cache/* /tmp/* /root/.cache

COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer
COPY resources/php/php.ini ${PHP_INI_DIR}/php.ini
COPY resources/mysql/client.cnf /etc/mysql/conf.d/client.cnf
COPY resources/mail/msmtprc /etc/msmtprc
COPY resources/mail/sendmail-wrapper /usr/local/bin/harbor-sendmail
RUN chmod 0644 /etc/msmtprc && chmod 0755 /usr/local/bin/harbor-sendmail
COPY resources/caddy/26.7.caddyfile /etc/caddy/Caddyfile
RUN ln -f /etc/caddy/Caddyfile /etc/frankenphp/Caddyfile

WORKDIR /app
ENV SERVER_ROOT=public/
ENV SERVER_NAME=https://localhost:8443
EXPOSE 8443
USER maho
