ARG PHP_VERSION=8.4
ARG DEBIAN_VARIANT=trixie
ARG HARBOR_PHP_EXTENSIONS=""
ARG NODE_VERSION=22.14.0
FROM node:${NODE_VERSION}-bookworm-slim AS node
FROM dunglas/frankenphp:php${PHP_VERSION}-${DEBIAN_VARIANT}

COPY --from=node /usr/local/ /usr/local/

ARG MAHO_UID=1000
ARG MAHO_GID=1000
ARG HARBOR_PHP_EXTENSIONS
ARG NODE_VERSION

RUN groupadd --gid "${MAHO_GID}" maho \
    && useradd --uid "${MAHO_UID}" --gid "${MAHO_GID}" --create-home --shell /bin/bash maho
RUN install -d /data /config \
    && chown -R maho:maho /data /config

RUN set -eux; \
    APT_PKGS="git patch unzip curl msmtp default-mysql-client postgresql-client sqlite3 \
        libasound2 libatk-bridge2.0-0 libatk1.0-0 libcairo2 libcups2 libdbus-1-3 \
        libdrm2 libgbm1 libgtk-3-0 libnspr4 libnss3 libpango-1.0-0 \
        libx11-6 libx11-xcb1 libxcb1 libxcomposite1 libxdamage1 libxfixes3 \
        libxkbcommon0 libxrandr2 libatspi2.0-0"; \
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
ENV PATH=/usr/local/bin:${PATH}
EXPOSE 8443
USER maho
