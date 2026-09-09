# systemd units

What runs in production. Copy them to `/etc/systemd/system/` (or symlink
that directory here), then `systemctl daemon-reload` and enable what you
use.

The paths follow the deployment convention:

| path | what it is |
| --- | --- |
| `/opt/reon` | this tree |
| `/opt/mobile-relay` | the adapter relay, a separate repository |
| `/opt/node` | Node.js |
| `/opt/reon/config.json` | configuration, outside version control |

Everything runs as the `reon` user, which must be able to read the tree
and write wherever each service writes.

## Long-running services

| unit | what it does |
| --- | --- |
| `reon-mail.service` | the game's SMTP and POP3. Uses `CAP_NET_BIND_SERVICE` for the low ports |
| `reon-mobile-relay.service` | the Mobile Adapter relay (Python, own venv) |

## Scheduled jobs

Each one has a `.service` (the job) and a `.timer` (when). Enable the
**timer**, not the service.

| timer | interval | what it does |
| --- | --- | --- |
| `reon-auto-schedule.timer` | 15 min | Pokémon news rotation and feature availability |
| `reon-mail-bottle.timer` | 15 min | message in a bottle |
| `reon-pokemon-exchange.timer` | 15 min | Trade Corner matching |
| `reon-pokemon-battle.timer` | daily | Battle Tower tally |
| `reon-service-status.timer` | 5 min | probes the services for the site's status panel |
| `reon-mail-trash-purge.timer` | daily, 04:30 | permanently deletes what passed the mail trash retention |

The trash purge is the only one that removes data for good. The retention
window lives in `MailUtil::TRASH_RETENTION_DAYS`, not here.
