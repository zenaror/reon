#!/usr/bin/env bash
set -Eeuo pipefail
# Service configuration is regenerated from the persistent checkout at each
# new container instance. The filesystem in /etc is intentionally not shared
# with the host. Only individual data directories are persistent; the dpkg
# database must stay with the image filesystem, never in a shared /var/lib.
mkdir -p /run/reon /srv/reon-install
printf 'REON_CONTAINER=1\nTLS_MODE=%s\nMAIL_MODE=%s\nINSTALL_PATCHES=%s\n' \
    "${TLS_MODE:-auto}" "${MAIL_MODE:-production}" "${INSTALL_PATCHES:-1}" > /run/reon/environment
chmod 0600 /run/reon/environment
exec /sbin/init
