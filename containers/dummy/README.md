# REON local homebrew dummy server

The dummy server serves the **real REON PHP website** and its administration screens,
backed by MySQL and the repository's Phinx migrations. Account creation,
preferences, device authentication and game content handlers use the REON code.
Node simulates internal SMTP/POP3 and DNS. No Oracle secrets or production data
are copied. The PHP dummy server image omits the .NET legality checker and production jobs.

## Start with Podman

From the repository root:

```sh
bash containers/dummy/stack.sh init
# Edit containers/dummy/.env: DUMMY_BIND and DUMMY_EXTERNAL_IP must be your LAN IPv4.
bash containers/dummy/stack.sh up
bash containers/dummy/stack.sh status
```

Services: `web` (PHP/Apache), `database` (MySQL 8.4), `migration` (one-off),
`email` (both SMTP and POP3), and `dns`. Persistent MySQL data survives `down`.
The generated private `.env` stays outside Git and image layers.
The development administrator is `devadmin`, password `dummyadmin1`.
Use it only in this isolated development stack.

Default host ports are HTTP **80**, SMTP **25**, submission **587** (mapped to
that same container port 25), POP3 **110**, and DNS **53/UDP**.
Rootless Podman requires host permission to bind low ports. The operator can
allow this for the current boot with
`sudo sysctl -w net.ipv4.ip_unprivileged_port_start=25`, then run
`bash containers/dummy/stack.sh standard-ports`.
High ports can be chosen with DUMMY_HTTP_PORT, DUMMY_SMTP_PORT,
DUMMY_SUBMISSION_PORT, DUMMY_POP3_PORT and DUMMY_DNS_PORT; games using standard ports
need those ports or an explicit transport mapping. DUMMY_BIND defaults to loopback.

## Create your own account

Open the normal REON `/signup.php`. Use a development email such as
`developer@reon.test`. Read its confirmation email at
`/dummy/inbox.php?address=developer@reon.test`, then follow the link to finish the
normal REON signup. This capture page is dummy server-only and intentionally readable by
local developers; never enter real personal data or expose this stack publicly.
Registered players use the real account page and download `mobile_config.bin`.
The generated configuration uses the dummy server DNS address; HTTP and POP3 must be reachable on 80 and 110. The dummy configuration enables
libmobile's native SMTP redirection from 25 to 587 and includes the configured
DNS port. Import this file into mGBA or Pico Adapter; use the eight-character
ISP password shown on the account page when the game asks for it.

## Publish content and exchange mail

Sign in to the **normal REON admin panel** to upload, edit and remove supported
game content, including `.cgb` files, and select personalized content. Existing
REON tables and opt-in checks remain authoritative. Binary content is stored by
the real handlers in MySQL, not a substitute SQLite schema.

Players use the normal REON webmail. Internal SMTP and POP3 use the same MySQL
accounts and dummy server mailbox table, including APOP with the downloaded device key.
The dummy server image substitutes only the mailbox storage implementation; production
continues to use Dovecot. Mail never relays to the Internet. `@reon.test` mail is
captured for registration; game mail requires a registered DION recipient.
DNS answers A queries with DUMMY_EXTERNAL_IP, with no Internet forwarding.

This is a development environment, not a production deployment or evidence of
physical Pico compatibility. P2P relay, production timers, external email,
TLS, patches and Pokemon legality checks are not supplied by this lightweight
stack. The full production profile remains under `setup-script/container/`.

## Local port exception

On the operator's current LAN the stack runs at `http://192.168.10.183/`,
POP3 110, SMTP **587 only** and DNS **5453/UDP**. Host port 25 is not published.
For this layout create a private `compose.local.yml` beside `compose.yml`:

```yaml
services:
  email:
    ports: !override
      - "${DUMMY_BIND}:587:25"
      - "${DUMMY_BIND}:110:110"
```

Set `DUMMY_HTTP_PORT=80`, `DUMMY_POP3_PORT=110`, `DUMMY_SMTP_PORT=587`,
`DUMMY_SUBMISSION_PORT=587` and `DUMMY_DNS_PORT=5453` in the private `.env`.
The host needs `net.ipv4.ip_unprivileged_port_start` at 80 or lower.
`stack.sh` automatically includes this optional override (Compose supports
`!override`); distribution defaults remain the standard ports above.

## Develop web tools and homebrew downloads

To edit the real PHP website directly, start with:

```sh
DUMMY_DEVELOPMENT=1 bash containers/dummy/stack.sh up
```

The development overlay mounts `web/`, preserves Composer dependencies in a
volume, and retains the dummy mailbox and adapter configuration overlays.
New pages and game handlers use the real REON routes and database. PHP changes
may take a few seconds to be noticed by the runtime. Stop with the same
`DUMMY_DEVELOPMENT=1` setting; omit it on a subsequent `up` to use image sources.

For a simple homebrew binary, put `0.example.cgb` in
`containers/dummy/content/`. It is served by the real authenticated download
router at `/cgb/download?name=/00/HBREW/0.example.cgb`, including GB00 account
authentication. A local file change needs no image rebuild. For supported games,
use the normal admin upload screens instead: their catalog metadata, binary
storage and personalized-content opt-in checks remain intact.

The migration seeds the repository's Game Boy Wars 3 maps and messages.
Other game content can be published through the real administration screens;
a fresh dummy database is not a copy of Oracle's content library.

## Validate the stack

```sh
python3 containers/dummy/flow-test.py --host 192.168.10.183 \
  --http 80 --smtp 587 --pop3 110 --dns 5453
```

Use ports matching your environment. The gate creates synthetic accounts and
mail, publishes and removes its own binary fixture, and checks real signup,
config, internal mail/webmail, GB00, opt-in and byte-exact content downloads.
See [VALIDATION.md](VALIDATION.md) for the emulator evidence and its limits.
The CI builds and gates both images, `reon-dummy-server` (email/DNS) and
`reon-dummy-web` (website/migration); it does not publish the production profile.
