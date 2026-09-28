# Versions aligned with production as of 2026-09-24. Checked on the machine,
# not chosen here: PHP 8.5.4, Node 22.11.0, .NET 9.0.317, MySQL 8.4.11,
# nginx 1.28.3. The Dockerfile was on PHP 8.3 and Node 25.2, which exist
# nowhere in the actual operation.
ARG NODE_VERSION=22-trixie
ARG NODE_VERSION2=22-alpine
ARG PHP_VERSION=8.5
ARG DOTNET_VERSION=9.0

### Legality checker
#See https://aka.ms/containerfastmode to understand how Visual Studio uses this Dockerfile to build your images for faster debugging.

FROM mcr.microsoft.com/dotnet/sdk:${DOTNET_VERSION} AS pokemon-legality
ARG DOTNET_VERSION
WORKDIR /src
COPY "app/pokemon-legality/LegalityCheckerConsole/LegalityCheckerConsole.csproj" /src/
RUN dotnet restore "LegalityCheckerConsole.csproj"
COPY "app/pokemon-legality/LegalityCheckerConsole/." .
RUN dotnet build "LegalityCheckerConsole.csproj" --no-restore -c Release --framework net${DOTNET_VERSION} -r linux-x64 --self-contained -o /app/pokemon-legality

### Web Service
FROM php:${PHP_VERSION} AS web-deps

WORKDIR /app

RUN apt-get -y update \
    && apt-get install -y --no-install-recommends git unzip \
 && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

COPY web/composer.json composer.json
COPY web/composer.lock composer.lock

RUN composer install --no-scripts --no-autoloader

COPY web /app

RUN composer dump-autoload --optimize

FROM php:${PHP_VERSION}-fpm AS web
WORKDIR /var/www
RUN apt-get -y update \
    && apt-get install -y --no-install-recommends libpng-dev libicu76 \
    && docker-php-ext-install mysqli gd \
    && docker-php-ext-enable mysqli gd \
    && apt-get remove -y libpng-dev \
    && rm -rf /var/lib/apt/lists/*
COPY --from=pokemon-legality /app/pokemon-legality /app/pokemon-legality
COPY --from=web-deps /app /var/www/reon/web
RUN mkdir -p /var/www/reon/web/tmp \
    && chown www-data:www-data /var/www/reon/web/tmp \
    && find /var/www/reon/web/htdocs -type f -exec chmod 644 {} \; \
    && find /var/www/reon/web/htdocs -type d -exec chmod 755 {} \;
ENV POKEMON_LEGALITY_BIN=/app/pokemon-legality/LegalityCheckerConsole

### Database Migration Service
FROM php:${PHP_VERSION} AS migrate
WORKDIR /var/www/reon

# Install MySQL client for database connectivity
RUN docker-php-ext-install mysqli pdo_mysql \
    && docker-php-ext-enable mysqli pdo_mysql

# Copy composer dependencies and phinx
COPY --from=web-deps /app /var/www/reon/web

# Copy migration files and config
COPY phinx.php /var/www/reon/phinx.php
COPY db/ /var/www/reon/db/

CMD ["/var/www/reon/web/vendor/bin/phinx", "migrate"]

### Mail Service
FROM node:${NODE_VERSION2} AS mail-deps
# Check https://github.com/nodejs/docker-node/tree/b4117f9333da4138b03a546ec926ef50a31506c3#nodealpine to understand why libc6-compat might be needed.
RUN apk update \
    && apk add --no-cache libc6-compat jq
WORKDIR /app
COPY mail/package.json mail/package-lock.json* ./
RUN npm ci

FROM node:${NODE_VERSION2} AS mail
WORKDIR /app
COPY --from=mail-deps /app/node_modules ./node_modules
COPY mail /app
# 10046, not 25/110. The service stopped being a mail server on
# 2026-09-12: Postfix answers 25 and Dovecot answers 110, and `disable_pop3`
# turned off the built-in POP3. What is left here are the side effects that
# have no owner on Dovecot's side -- the Sent copy and the bell entry -- and
# they listen on 10046 (mail/sideEffects.js).
#
# Postfix and Dovecot are NOT containerised. In a pure Docker install, this
# target does not deliver any mail on its own; see setup-script/2-setup-postfix-bridge.sh.
EXPOSE 10046

ENTRYPOINT ["/app/entrypoint.sh"]

### Outbound relay policy
#
# Postfix's policy delegation: it asks, on 10045, whether that send may go
# out. Same base as mail, a different entry point. Was missing from Docker
# entirely, even though it is an active service in production
# (reon-relay-policy.service).
FROM node:${NODE_VERSION2} AS relay-policy
WORKDIR /app
COPY --from=mail-deps /app/node_modules ./node_modules
COPY mail /app
EXPOSE 10045
CMD ["node", "relayPolicy.js", "-c", "/app/config.json", "-p", "10045"]


### Cron jobs

FROM node:${NODE_VERSION} AS battle-deps
WORKDIR /app
COPY app/pokemon-battle/package.json app/pokemon-battle/package-lock.json* ./
RUN npm ci

FROM node:${NODE_VERSION} AS exchange-deps
WORKDIR /app
COPY app/pokemon-exchange/package.json app/pokemon-exchange/package-lock.json* ./
RUN npm ci

FROM node:${NODE_VERSION} AS auto-schedule-deps
WORKDIR /app
COPY app/auto-schedule/package.json app/auto-schedule/package-lock.json* ./
RUN npm ci

FROM node:${NODE_VERSION} AS bottle-deps
WORKDIR /app
COPY app/mail-bottle/package.json app/mail-bottle/package-lock.json* ./
RUN npm ci

# Based on https://github.com/AnalogJ/docker-cron
FROM node:${NODE_VERSION} AS cron
# There used to be a `RUN` with no argument on this line, and it broke the
# whole build -- "RUN requires at least one argument". The cron target had
# not built since it appeared.
# libicu76 is the soname from Debian trixie, the base of node:22-trixie. It
# exists because of the legality checker's self-contained binary (.NET), not
# because of Node. Changing the base changes the number and the build
# breaks -- if that happens, the way out without ICU is
# ENV DOTNET_SYSTEM_GLOBALIZATION_INVARIANT=1, which drops the dependency at
# the cost of culture-sensitive string comparison.
RUN apt-get -y update \
    && apt-get install -y --no-install-recommends curl tzdata libicu76 \
    && rm -rf /var/lib/apt/lists/*

# Latest releases available at https://github.com/aptible/supercronic/releases
ENV SUPERCRONIC_URL=https://github.com/aptible/supercronic/releases/download/v0.2.41/supercronic-linux-amd64 \
    SUPERCRONIC_SHA1SUM=f70ad28d0d739a96dc9e2087ae370c257e79b8d7 \
    SUPERCRONIC=supercronic-linux-amd64

RUN curl -fsSLO "$SUPERCRONIC_URL" \
    && echo "${SUPERCRONIC_SHA1SUM}  ${SUPERCRONIC}" | sha1sum -c - \
    && chmod +x "$SUPERCRONIC" \
    && mv "$SUPERCRONIC" "/usr/local/bin/${SUPERCRONIC}" \
    && ln -s "/usr/local/bin/${SUPERCRONIC}" /usr/local/bin/supercronic

COPY app/docker_entry.sh /entrypoint.sh
COPY app/docker.crontab /etc/cron.d/crontab
RUN chmod 0644 /etc/cron.d/crontab

ENTRYPOINT ["/entrypoint.sh"]

WORKDIR /app

COPY app/pokemon-battle pokemon-battle
COPY --from=battle-deps /app/node_modules ./pokemon-battle/node_modules

COPY app/pokemon-exchange pokemon-exchange
COPY --from=exchange-deps /app/node_modules ./pokemon-exchange/node_modules

COPY app/mail-bottle mail-bottle
COPY --from=bottle-deps /app/node_modules ./mail-bottle/node_modules

COPY app/auto-schedule auto-schedule
COPY --from=auto-schedule-deps /app/node_modules ./auto-schedule/node_modules

COPY --from=pokemon-legality /app/pokemon-legality /app/pokemon-legality
ENV POKEMON_LEGALITY_BIN=/app/pokemon-legality/LegalityCheckerConsole

COPY app/bxt_config_loader.js /app/

CMD ["/usr/local/bin/supercronic", "/etc/cron.d/crontab"]

### Cron jobs (PHP)
#
# Four of production's eight timers are PHP, not Node: the mail trash
# cleanup, the retention purge, the service status check, and the seeded-data
# touch. They were missing from Docker entirely -- the cron target above only
# knows how to run Node.
#
# A separate image instead of PHP squeezed into the Node image: these are two
# dependency chains that do not mix, and combining them would make each one
# carry the other.
FROM php:${PHP_VERSION}-cli AS cron-php
RUN apt-get -y update \
    && apt-get install -y --no-install-recommends curl tzdata \
    && docker-php-ext-install mysqli \
    && docker-php-ext-enable mysqli \
    && rm -rf /var/lib/apt/lists/*

ENV SUPERCRONIC_URL=https://github.com/aptible/supercronic/releases/download/v0.2.41/supercronic-linux-amd64 \
    SUPERCRONIC_SHA1SUM=f70ad28d0d739a96dc9e2087ae370c257e79b8d7 \
    SUPERCRONIC=supercronic-linux-amd64

RUN curl -fsSLO "$SUPERCRONIC_URL" \
    && echo "${SUPERCRONIC_SHA1SUM}  ${SUPERCRONIC}" | sha1sum -c - \
    && chmod +x "$SUPERCRONIC" \
    && mv "$SUPERCRONIC" "/usr/local/bin/${SUPERCRONIC}" \
    && ln -s "/usr/local/bin/${SUPERCRONIC}" /usr/local/bin/supercronic

WORKDIR /var/www/reon
# The whole web tree, with composer's vendor: purge_mail_trash,
# purge_retention and check_service_status all load classes from
# web/classes.
COPY --from=web-deps /app /var/www/reon/web
COPY maint/ /var/www/reon/maint/
COPY app/docker-php.crontab /etc/cron.d/crontab
RUN chmod 0644 /etc/cron.d/crontab

CMD ["/usr/local/bin/supercronic", "/etc/cron.d/crontab"]

### DNS server

FROM alpine:3.20 AS dns
RUN apk --no-cache add dnsmasq
COPY docker-dns-entry.sh /entrypoint.sh
EXPOSE 53/udp
ENTRYPOINT ["/entrypoint.sh"]
