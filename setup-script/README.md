# Server setup scripts

Install REON + mobile-relay straight onto a VM (no Docker), sized for an
Oracle Cloud Always Free instance with 1 GB of RAM. Four scripts, run in
order, all idempotent:

| script | what it does |
| --- | --- |
| `1-setup-reon.sh` | everything below: packages, MySQL, PHP, Node, .NET, Python, dnsmasq, nginx, HTTPS, systemd units |
| `2-setup-postfix-bridge.sh` | Postfix in front of the game's mail: real internet e-mail in and out |
| `3-harden-server.sh` | fail2ban, key-only SSH, unused services off, nginx security headers |
| `4-harden-bots.sh` | blocks search/AI crawlers by User-Agent (re-run after every `1-setup-reon.sh`) |

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
   other three in order.
4. Run them again whenever you want to update (re-running is safe).

## What it installs

- **nginx + php-fpm** → the website
- **MySQL** → the database
- **Node.js** → the mail service (SMTP/POP3) and the 4 cron jobs (Battle
  Tower, Trade Corner, news, Mail de Cute)
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
- **systemd** → every service becomes a unit (reon-mail, reon-mobile-relay,
  the cron timers) and restarts on its own if it dies; see
  `../examples/systemd/README.md`

## Main decisions

- Everything is symlinked from `/opt/reon` and `/opt/mobile-relay` to the
  folder you ran the script from. Updating the code = replacing the files
  and running the script again, nothing to reconfigure.
- Ports: the mail service listens only on 25/110 internally; 587 is
  redirected to 25 (iptables). DNS listens on 5453; the standard port 53 is
  redirected to 5453.
- Firewall (iptables) opened automatically. **Oracle also filters in the
  cloud** (Security List/NSG) — the script cannot configure that, it has to
  be opened by hand in the console: 22, 80, 443, 25, 587, 110, 31227 (TCP)
  and 53, 5453 (UDP).

## Tuning for a 1 GB VM

- Creates a 2 GB swapfile automatically when it detects little RAM.
- MySQL with a reduced buffer pool and connection count, performance_schema
  off (the Docker mysql:8.4 image reached ~500 MB; this is much smaller).
- php-fpm in `ondemand` mode (a process only starts when there is a request).
- Temporary downloads (Node, .NET) use the large disk instead of `/tmp`,
  which tends to be too small on these VMs.
- Automatic `npm audit fix` after installing each Node app — fixes known
  vulnerabilities that need no major version change (does not force
  upgrades that could break the code).
- `apt-get clean` at the end so no package cache piles up.

## Logs

Once installed, these become global commands:

- `reon-status` — status of everything
- `reon-logs-all` / `reon-logs-mail` / `reon-logs-web` / `reon-logs-relay` /
  `reon-logs-dns` / `reon-logs-cron` — follow the logs live
- `reon-add-user` — create the first account without any e-mail configured

And two files in `/var/log/reon/`, owned by the php-fpm user:

- `php-error.log` — PHP errors and every `error_log()` call from the site.
  Without it the pool discards the workers' output and nothing the code
  logs reaches anywhere; this is where, for example, an account e-mail the
  relay refused shows up.
- `magbtest.log` — instrumentation of the MAGB TestSuite ROM's requests
  (`/MAGBTEST/` paths only; real game traffic is not logged).
