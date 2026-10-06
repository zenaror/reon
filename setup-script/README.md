# Server setup scripts

Install REON + mobile-relay straight onto a VM (no Docker), sized for an
Oracle Cloud Always Free instance with 1 GB of RAM. Six scripts, run in
order, all idempotent (the fifth and sixth are optional), plus two helpers:

| script | what it does |
| --- | --- |
| `1-setup-reon.sh` | everything below: packages, MySQL, PHP, Node, .NET, Python, dnsmasq, nginx, HTTPS, systemd units |
| `2-setup-postfix-bridge.sh` | The mail system: Postfix (port 25, real internet e-mail in and out, a banned account refused as a sender) delivering by LMTP to Dovecot, which stores the mail in Maildir, runs the Sieve filter that shapes it for the Game Boy, and answers POP3 on :110 with APOP. Installs the Dovecot packages, the `vmail` user, the config from `examples/dovecot/`, a certbot deploy hook that reloads Postfix, and sets `mail_store`, `disable_pop3` and `shaped_at_delivery` in `config.json` |
| `3-harden-server.sh` | fail2ban (jails: `sshd`, `postfix`, `reon-pop3` — repeated failed POP3 logins, `reon-web-scan` — bans on the first request probing `.env`/`.git`/phpunit/WordPress files, and `reon-manual` — the bans an admin adds by hand), key-only SSH (only when a real login key exists for the invoking user), unused services off, nginx security headers, restart-on-failure for nginx, dovecot and dnsmasq |
| `4-harden-bots.sh` | blocks search/AI crawlers by User-Agent (re-run after every `1-setup-reon.sh`) |
| `5-admin-control.sh` | what the admin panel's Services page and Banned IPs page need: two small root helpers (`reon-admin-ctl`, `reon-ban-ctl`), each behind a single sudoers entry. Optional; without it the panel says so and does nothing |
| `6-setup-rom-patches.sh` | the game-patch routine behind the Downloads page: build packages, rgbds 0.6.1 and 1.0.3 built from source (checked against a SHA-256), the `reonpatch` user and `/var/lib/reon-patches`, `reon-patch-build` and its daily timer. Optional. The official ROMs are not part of it: they are put away once with `reon-patch-build add-rom`. See `maint/rom-patches/README.md` |
| `pull-backups.sh` | not part of the install: run it from your own computer to copy the nightly backups (databases and the mailbox archive) off the server over SSH |
| `reon-menu.sh` | the terminal menu; installed as `reon-menu` by script 1 |

## Usage

1. On the VM, clone `reon` and `mobile-relay` side by side:
   ```
   git clone https://github.com/zenaror/reon
   git clone https://github.com/zenaror/mobile-relay
   ```
   (The scripts also accept the packaged layout: a folder holding `reon/`,
   `mobile-relay/` and the scripts themselves.)
2. Prepare the config files (the script warns and stops if one is missing):
   - `reon/.env`
   - `reon/config.json`
   - `mobile-relay/config.ini`
3. Run as root: `sudo bash reon/setup-script/1-setup-reon.sh`, then the
   other five in order (2, 3, 4 and, if you want them, 5 for the admin panel's controls and 6 for the game patches).
4. Run them again whenever you want to update (re-running is safe).

## What it installs

- **nginx + php-fpm** → the website
- **MySQL** → the database
- **Postfix + Dovecot** → the mail system (script 2)
- **Node.js** → the mail side-effects worker and the 4 Node jobs (Battle
  Tower, Trade Corner, news, Mail de Cute); the PHP jobs (mail trash,
  retention, service status), the nightly backup, the session sweep and the
  monthly reboot are timers too
- **.NET** → the Pokémon legality checker the site uses
- **Python** → mobile-relay
- **dnsmasq** → the fake DNS that lets the Game Boy find the server
- **certbot** → automatic HTTPS (when the `hostname` in config.json is a
  real domain with DNS already pointing here). With a certificate, a
  browser on plain HTTP at that hostname is redirected to HTTPS — **only
  browsers, only on that hostname**: `/cgb/`, `/api/`, the `/NN/` content
  and any request without a `Host` header stay on HTTP, because the Game
  Boy speaks no TLS and follows no 301, and certbot's renewal also needs
  HTTP on `/.well-known/`
- **reon-menu and ~/shortcuts** → a terminal menu and a folder of links to everything used to run the server (see "Running the server from a terminal")
- **systemd** → every service becomes a unit (reon-mail, reon-mobile-relay,
  the cron timers) and restarts on its own if it dies; see
  `../examples/systemd/README.md`

## Main decisions

- Everything is symlinked from `/opt/reon` and `/opt/mobile-relay` to the
  folder you ran the script from. Updating the code = replacing the files
  and running the script again, nothing to reconfigure.
- Ports: Postfix listens on 25 and Dovecot on 110; the reon-mail worker only
  on 127.0.0.1:10046. 587 is redirected to 25 (iptables). DNS listens on 5453; the standard port 53 is
  redirected to 5453.
- Firewall (iptables) opened automatically. **Oracle also filters in the
  cloud** (Security List/NSG) — the script cannot configure that, it has to
  be opened by hand in the console: 22, 80, 443, 25, 587, 110, 31227 (TCP)
  and 53, 5453 (UDP).

## Tuning for a 1 GB VM

- Creates a 2 GB swapfile automatically when it detects little RAM.
- MySQL with a reduced buffer pool (64 MB; the database is ~10 MB) and
  connection count, performance_schema off. The tuning file must be a real
  file, never a link: AppArmor blocks mysqld from following it and the
  settings silently do not apply (see docs/OPERATIONS.md, "MySQL memory") (the Docker mysql:8.4 image reached ~500 MB; this is much smaller).
- php-fpm in `ondemand` mode (a process only starts when there is a request).
- Temporary downloads (Node, .NET) use the large disk instead of `/tmp`,
  which tends to be too small on these VMs.
- Automatic `npm audit fix` after installing each Node app — fixes known
  vulnerabilities that need no major version change (does not force
  upgrades that could break the code).
- `apt-get clean` at the end so no package cache piles up.
- The site caches compiled templates and the parsed translations under
  `/tmp/reon/reon-twig-<uid>/` (the pool's temp dir, created and owned by the
  pool user by `setup_php_web`). Without that cache every page re-parses seven
  locale files and takes about half a second even when it is trivial; with it,
  10-80 ms. Nothing to configure: the cache is rebuilt by itself when a
  template or a locale file changes, so updating the code by copying files
  needs no cache clearing. If `/tmp/reon` is not writable by the pool user the
  site still works, only slowly -- check that first if pages get slow.
- Logs: `logrotate` is installed explicitly (the base packages are installed
  without recommends, which left it out and meant nothing was ever rotated);
  the site's own files in `/var/log/reon/` get a 14-day rule, and nginx,
  php-fpm and fail2ban use the ones their packages ship. The journal is
  capped at 30 days. `reon-logs-files [web|php|activity|fail2ban|all]` follows the
  logs that live in files, next to `reon-logs-all` for the journal.
- Backups: `reon-db-backup.timer` dumps every non-system MySQL database (and
  the relay's SQLite file, if it uses one) at 03:30 into `/var/backups/reon/`
  (root-only), keeping 7 days, and a tar of the mailboxes (`/var/vmail`).
  It is a copy on the same disk -- copy the folder elsewhere
  (`pull-backups.sh`) to survive losing the machine.
- nginx, dovecot and dnsmasq get `Restart=on-failure` (a systemd drop-in, from
  `3-harden-server.sh`); the distribution ships them without a restart policy.
- `/tmp` is tmpfs (emptied at every boot, the monthly reboot included):
  `/etc/tmpfiles.d/reon.conf` recreates `/tmp/reon` (sessions and the template
  cache) and keeps the 10-day `/tmp` cleanup away from it. `vm.swappiness` is
  set in `/etc/sysctl.d/99-reon.conf` for the same reason (`/etc/sysctl.conf`
  is not read).
- The list of usernames nobody may register (`system`, `nintendo`, `admin`,
  ...) is a database table filled by a migration (`sys_reserved_usernames`),
  so it needs no step here; it is edited from the admin panel under Users ->
  Reserved names. A fresh install starts with the seeded list.

## Running the server from a terminal

Everything below is created by `1-setup-reon.sh` (`generate_log_scripts` and
`setup_shortcuts`), so a fresh install gets it too. Nothing here is a new
capability: each entry is a plain `systemctl` / `journalctl` call, saved
from having to remember the names.

**`reon-menu`** (also `~/reon-menu`; source: `setup-script/reon-menu.sh`)

| | |
| --- | --- |
| 1 Status | services, timers with next/last run, failed units |
| 2 Follow a log | everything, mail, mobile relay, web, jobs, the PHP site log, nginx, fail2ban |
| 3 Recent warnings and errors | last 24 h of the services, plus the error/warn lines of the PHP log |
| 4 Restart a service | picks from the list, asks first |
| 5 Run a job now | Battle Tower, Trade Corner, news, Mail de Cute, purges, status probe, backup |
| 6 Banned addresses | lists them; banning and unbanning stay in the admin panel, where a reason is required |
| 7 Backups | lists `/var/backups/reon`, offers to run one now |
| 8 Load, memory, disk | uptime, `free`, `df`, top processes by memory |
| 9 Database shell | `mysql` on the site's database |

**`~/shortcuts/`**: links only (nothing is copied, so nothing goes stale, and
deleting the folder removes only the links). Made in the home of
`$SHORTCUT_USER` (default: the user who ran `sudo`, else `ubuntu`).

| link | goes to |
| --- | --- |
| `site` | `/opt/reon`, the running site |
| `docs`, `setup-scripts` | `/opt/reon/docs`, `/opt/reon/setup-script` |
| `config.json` | the site's configuration (holds secrets) |
| `logs-php`, `logs-web` | `/var/log/reon`, `/var/log/nginx` |
| `backups` | `/var/backups/reon` (root only: `sudo ls ~/shortcuts/backups/`) |
| `systemd-units` | `/etc/systemd/system` (the `reon-*` units and timers) |
| `nginx`, `postfix`, `dovecot`, `fail2ban` | their configuration folders |
| `commands/` | every `reon-*` command |

Re-running the setup refreshes the links and never overwrites a real file or
folder of the same name. To add a place, add a `link <name> <target>` line to
`setup_shortcuts` in `1-setup-reon.sh`. The same page of the admin panel,
`/admin/logs.php`, shows the service logs and the PHP site log without a
terminal.

## Logs

Once installed, these become global commands:

- `reon-menu` — the terminal menu, see "Running the server from a terminal" above
- `reon-status` — status of everything
- `reon-logs-all` / `reon-logs-mail` / `reon-logs-web` / `reon-logs-relay` /
  `reon-logs-dns` / `reon-logs-cron` / `reon-logs-postfix` /
  `reon-logs-dovecot` / `reon-logs-relay-policy` — follow the logs live
- `reon-logs-files [web|php|activity|fail2ban|all]` — the logs that live in files
- `reon-add-user` — create the first account without any e-mail configured

And two files in `/var/log/reon/`, owned by the php-fpm user:

- `php-error.log` — PHP errors and every `error_log()` call from the site.
  Without it the pool discards the workers' output and nothing the code
  logs reaches anywhere; this is where, for example, an account e-mail the
  relay refused shows up.
- `magbtest.log` — instrumentation of the MAGB TestSuite ROM's requests
  (`/MAGBTEST/` paths only; real game traffic is not logged).
