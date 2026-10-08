# Production container profile

This profile runs the real native REON installation inside an Ubuntu 24.04
systemd container. It includes the website, MySQL, Postfix, Dovecot, mail policy,
Node game workers, PHP maintenance timers, mobile-relay, legality checker and
optional ROM patch toolchains. It is separate from the dummy server published by CI.
Package/runtime choices come from the six installation scripts.

## Build

Keep `reon` and `mobile-relay` side by side. From `reon`:

```sh
podman build --format docker --build-context relay=../mobile-relay \
  -f setup-script/container/Containerfile -t reon-production .
```

The relay named context copies only the code and its example configuration;
its tracked `config.ini` is excluded. REON credentials and `.env` are excluded
by `.dockerignore`. Neither ROMs nor a production database belong in this image.

## Run

Prepare a private directory outside Git holding `reon/config.json`,
`reon/.env` and `mobile-relay/config.ini`. Use the native install settings and
local MySQL host `127.0.0.1`. Mount it read-only at `/run/reon-secrets` on first
boot. The installer copies private configuration into persistent `/srv/reon-install`.
Later edits belong in that persistent checkout; restart to reconfigure.

```sh
podman run -d --name reon-production --systemd=always \
  -p 8080:80 -p 8443:443 -p 2525:25 -p 1110:110 \
  -p 31227:31227 -p 5353:5453/udp \
  -v /absolute/private-config:/run/reon-secrets:ro \
  -v reon-code:/srv/reon-install -v reon-db:/var/lib/mysql \
  -v reon-mail:/var/vmail -v reon-queue:/var/spool/postfix \
  -v reon-home:/var/lib/reon \
  -v reon-patches:/var/lib/reon-patches -v reon-captures:/var/lib/reon-captures \
  -v reon-install-state:/var/lib/reon-container -v reon-backups:/var/backups/reon \
  -v reon-runtime:/opt -v reon-logs:/var/log \
  -v reon-certs:/etc/letsencrypt \
  reon-production
```

First boot installs dependencies from official repositories, runs migrations
and configures the services; allow the same time/disk as the native installer.
Follow progress with `podman logs reon-production` or
`podman exec reon-production journalctl -u reon-bootstrap -f`.
Check readiness with `podman exec reon-production systemctl is-active reon-bootstrap`
and then verify the application/mail endpoints. A failed bootstrap is not a
ready deployment. On each new container boot, code from the image is overlaid
on persistent source and the scripts run again. Back up volumes before upgrades.

For an isolated test add `-e TLS_MODE=disabled -e MAIL_MODE=internal`;
configure the private file's mail domain as `mail.reon.test`. For production,
use the real domain; expose standard host ports required by clients and
certbot (80/443). Runtime TLS and outbound mail need real DNS and operator
configuration, just as on a native installation. `INSTALL_PATCHES=0` omits
optional toolchains. Original ROMs must be supplied separately through the
patch builder; they are never obtained from Oracle by this profile.

## Host responsibilities and limitations

The container skips swap creation, host firewall/port-redirection rules,
SSH hardening and fail2ban installation; the host or ingress must supply those.
The application security rules and admin service controls run inside the
container. Monthly host reboot is disabled. A container restart is controlled
by the runtime. DNS is exposed directly from internal 5453 and submission can
be mapped from host 587 to internal 25. Do not share the host's `/etc`, network
namespace or systemd sockets, and do not use `--privileged`.

A Linux systemd-capable container runtime and cgroup delegation are required.
Package versions on Ubuntu 24.04 differ from Oracle 26.04; feature parity does
not mean identical packages. Persistent state and the mounted config are
private. Backups and restores, Internet mail deliverability, DNS/TLS, host
scanner bans and original-ROM patch output require operator validation before
using this profile as production. Do not replace Oracle with an unvalidated
container or copy its accounts/secrets into development fixtures.

Never mount all of `/var/lib`: sharing the dpkg database would make a new
container believe packages are installed while their binaries and service
units are absent. Persist only the data directories listed above.
