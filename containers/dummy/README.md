# REON dummy server: local homebrew fixtures

One Node process, zero npm dependencies. Simulated HTTP content, SMTP, POP3
and DNS let a homebrew developer test downloads and internal messages without
installing REON's database, website, Postfix, Dovecot or patch toolchains.
This is a fixture SDK, not the production server or a complete cartridge emulator.

## Run

From the repository root:

```sh
mkdir -p containers/dummy/content
cp containers/dummy/routes.example.json containers/dummy/content/routes.json
printf '\001\002\003\004' > containers/dummy/content/content.bin
podman build --skip-unused-stages=true --format docker --target dummy-server -t reon-dummy-server .
podman run --rm --name reon-sdk \
  -p 127.0.0.1:8080:8080 -p 127.0.0.1:2525:2525 \
  -p 127.0.0.1:1110:1110 -p 127.0.0.1:5353:5353/udp \
  -v ./containers/dummy/content:/content:ro reon-dummy-server
```

Open `http://127.0.0.1:8080/`. Docker works with the same target (omit Podman's
`--skip-unused-stages` option), or use `docker compose -f containers/dummy/compose.yml up --build`.
For a persistent mailbox add a named volume mounted at `/data`; without one,
remove the anonymous volume when cleaning up with `podman rm -v`.

## Publish test content

`/content/routes.json` is an array of HTTP fixtures. Each route has `path`,
optional `method` (default GET), `host`, `status`, `headers` and one of `body`,
`bodyBase64` or `file`. File paths stay inside `/content`. GET binary responses
retain every byte, including zero bytes. Paths and methods match exactly;
query parameters do not change the match. Files reload on each request.
POST fixtures return a fixed response; they do not execute gameplay logic.
Unknown routes return 404. Errors in a fixture return 500 and log a description.
Mount a directory to edit files without rebuilding the image.

Use `/_sdk/routes` to inspect the currently loaded routes and `/_sdk/mail`
to inspect messages (raw MIME is base64). The SDK homepage is intentionally
small: publish files through your editor, rather than a production admin panel.

## Internal mail

SMTP on 2525 accepts known recipients at `mail.reon.test` or `reon.dion.ne.jp`
and rejects external recipients. Optional SMTP AUTH PLAIN/LOGIN accepts the
same fixture credentials (delivery remains internal). POP3 on 1110 supports USER/PASS and APOP,
STAT, LIST, UIDL, RETR, DELE, RSET and QUIT. Deletions commit on QUIT.
Messages are stored locally and delivered as submitted; no charset shaping,
Sieve, Internet relay, device authorization or production policy is simulated.

Default fixture accounts:

| Account | Alias | Password |
| --- | --- | --- |
| player01 | g000000007 | test0001 |
| player02 | g000000008 | test0002 |

Override them with `/content/accounts.json`, an array of objects with `name`,
`password` and optional `aliases`. Restart after account changes. These are
local fixture credentials, never real accounts. APOP uses this fixture password,
not REON's real device key provisioning process.

## DNS and adapter setup

DNS UDP 5353 returns `SDK_EXTERNAL_IP` (default `127.0.0.1`) for every A query;
other record types receive no answer. It does not forward Internet queries.
Point your development adapter at the SDK address and its published ports.
If the adapter expects standard ports, map host 80 to container 8080, 25 to
2525, 110 to 1110 and UDP 53 to 5353 instead. Those host ports may need root
or system configuration and must be free. For a separate device set
`SDK_EXTERNAL_IP` to the host's LAN IPv4 address and explicitly bind the
ports to that interface. Keep the default loopback bindings for local tests.

The SDK does not generate `mobile_config.bin`, implement signed device-auth,
P2P relay, rankings, official game catalogs, opt-in policy, ROM patches or
Pokémon legality checks. Supply your game's response fixtures and client test
configuration. Protocols and binary formats remain the caller's responsibility;
success here is not hardware or production compatibility evidence.

## CI

The image workflow publishes only the `dummy-server` target as
`ghcr.io/<owner>/reon-dummy-server`. Production containers are built separately
with `setup-script/container/Containerfile` and real native service units.

## Smoke check

Use a disposable directory mounted at `/content` and run
`python3 containers/dummy/smoke-test.py --content /absolute/disposable-directory`.
The check writes its own route fixtures and exercises binary downloads, POST,
internal mail, external refusal, APOP, deletion and DNS. Use its port flags
when the host mappings differ from the defaults. It uses the default fixture
accounts and default DNS answer. CI runs this gate before publishing the SDK.
