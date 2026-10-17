# REON

REON is a service replacement for Nintendo's [Mobile Adapter GB](https://bulbapedia.bulbagarden.net/wiki/Mobile_Game_Boy_Adapter) service for the Game Boy Color and Advance. This service aims to target all officially released games, as well as potentially allow homebrew games to use the service.

# AI assistance in this fork

Parts of our modifications in this fork were developed with assistance from AI
tools, including code generation. This statement applies to our modifications
and does not describe the upstream project's development process.

# Services

This repository holds various folders for the service, and each has its own README.md which should be checked out.

- `mail/` holds the Node.js mail server by [Matze](https://github.com/Sudel-Matze)
- `app/` holds various non-web applications, such as the Pokémon Trade Corner program by [thomasnet](https://github.com/thomasnet-mc)
- `web/` holds the web-facing section of the service; this includes scripts for various games.

# Local development and project map

For local game and web development, start with the
[dummy server](containers/dummy/README.md). It uses the real REON website and
game handlers with MySQL, while Node provides local email and DNS. It is an
isolated development environment; emulator results and synthetic checks do not
establish hardware compatibility or production readiness. Dated validation and
remaining limits are in [VALIDATION.md](containers/dummy/VALIDATION.md).

| Responsibility | Existing location |
| --- | --- |
| Contributor instructions | [AGENTS.md](AGENTS.md) |
| Application code | `web/`, `mail/`, `app/`, shared helpers in `lib/` |
| Database schema and migrations | [db/](db/README.md) |
| Technical and operational documentation | [docs/OPERATIONS.md](docs/OPERATIONS.md), game-specific documents under `docs/` |
| Installation and production containers | [setup-script/](setup-script/README.md), [container profile](setup-script/container/README.md) |
| Maintenance tools | `maint/`, `web/scripts/`, [ROM patch tools](maint/rom-patches/README.md) |
| Existing checks and evidence | `web/tests/`, `containers/dummy/flow-test.py`, [validation record](containers/dummy/VALIDATION.md), `.github/workflows/` |
| Change history | [docs/CHANGELOG.md](docs/CHANGELOG.md) |

The upstream layout is retained; no separate `src/` or `tools/` directory is
required. Ongoing production planning lives in the workspace's `PENDENCIAS.md`
and `CONCLUIDAS.md` outside Git, with handoffs in OMM scope `reon-production`.
Those operator records are not prerequisites for using this repository.

# Server Setup

The production server runs without Docker, installed by the scripts in
[`setup-script/`](setup-script/README.md): clone `reon` and
`mobile-relay` side by side on the VM, prepare `reon/.env`,
`reon/config.json` and `mobile-relay/config.ini`, then run as root

```
sudo bash reon/setup-script/1-setup-reon.sh
sudo bash reon/setup-script/2-setup-postfix-bridge.sh
sudo bash reon/setup-script/3-harden-server.sh
sudo bash reon/setup-script/4-harden-bots.sh
```

Re-running them is safe and is how the server is updated. The systemd
units they install are described in [`examples/systemd/`](examples/systemd/README.md),
the database migrations in the [database README](db/README.md). The
mail system — Postfix and Dovecot, the real-internet bridge, per-device
authorization, APOP — is covered in the changelog's `reon-mail` section.
A per-project changelog is kept in [`docs/CHANGELOG.md`](docs/CHANGELOG.md)
(in English).

# Docker Setup

For the local dummy image, follow [its current guide](containers/dummy/README.md).
For the full production container profile, follow
[setup-script/container/](setup-script/container/README.md); production ingress,
mail delivery and restore still require environment-specific validation. The
root Compose recipe below is retained as the legacy setup path.

A `docker-compose.yml` is provided which will run all the required services. To get started, be sure to have docker available on your system.

1. Copy `config.example.json` to `config.json`
2. Copy `example.env` to `.env` (note the `.` at the start of the file name!)
3. Update the values in both files as needed, ensuring that the mysql options match
   - The included compose file exposes the MySQL port, so make sure you choose secure passwords
   - Do NOT change the `mysql_host` option in `config.json` unless you plan to use an external MySQL database
   - MYSQL_ROOT_PASSWORD is only needed for the `db` container and is not used by the other services
4. Run `docker compose up -d` to start the services
5. Run `docker compose exec -w /var/www/reon/web/scripts web php add_user.php` to create an account without having to configure email delivery

Your REON server should now be accessible at http://localhost/.

# License

This code is licenced under MIT.
