# Claude artifact documents

The two Claude artifacts were converted to Markdown on 2026-10-07. English
is the primary version; Brazilian Portuguese files use the `.Br.md` suffix.
Each language comes from the artifact's own text rather than a new translation.

| Document | English | Português (Brasil) |
| --- | --- | --- |
| Data protection register | [Register](DATA_PROTECTION_REGISTER.md) | [Registro](DATA_PROTECTION_REGISTER.Br.md) |
| Game Boy mail memo | [Memo](GAME_BOY_MAIL_MEMO.md) | [Memo](GAME_BOY_MAIL_MEMO.Br.md) |

These documents preserve their September 2026 narratives, including
historical evidence, decisions, unresolved questions and qualifications. Their
statements about production and law were not re-audited during conversion.
The register retains the original confidentiality notice. At the owner's request,
the duplicated project changelog was removed from both Memo versions; only
[CHANGELOG.md](../CHANGELOG.md) maintains that list. The register's own revision
log remains part of its evidence history. Personal account identifiers in examples
were replaced with fictitious values on 2026-10-07; protocol structure, source
links and technical provenance are preserved.

For subsequent implementation and operations, see [Operations](../OPERATIONS.md),
[Changelog](../CHANGELOG.md), and [Net de Get](../NET_DE_GET.md).

Original sources:

- [REON Data Protection Register](https://claude.ai/code/artifact/be2e0466-bfa8-4cbb-8fd3-c2849a7fe155)
- [Correio do Game Boy](https://claude.ai/code/artifact/e54c85bd-5416-41ba-afbd-d1cafcf1991e)

## Consistency review (2026-10-07)

The current change summary is [CHANGELOG.md](../CHANGELOG.md). The artifacts
retain their narrative text, with English and Portuguese notes identifying
superseded passages. The Memo's former cross-project change list was a September
snapshot; it has been replaced by a link to the repository changelog.

| Topic | Conflict or omission | Current evidence / resolution |
| --- | --- | --- |
| Notification deletion | Memo narrative says there is no delete button; the source artifact's former final list said the owner could clear notifications. | [notifications.php](../../web/htdocs/user/notifications.php) handles authenticated, CSRF-protected clearing; [NotificationUtil.php](../../web/classes/NotificationUtil.php) deletes only that user's rows. Original narrative annotated. |
| Notification and audit retention | Memo says records are never erased; the register lists indefinite retention. | [purge_retention.php](../../web/scripts/purge_retention.php): notifications and audit rows, 365 days; outbound-mail records, 90 days; device IPs, 30 days, counters retained. Audit is append-only through the panel, not exempt from retention. Production windows and active timer checked over SSH on 2026-10-07. |
| Journal retention | Register finding 18 says disk capacity is the only limit. | [OPERATIONS.md](../OPERATIONS.md) and production `/etc/systemd/journald.conf.d/reon-retention.conf`: `MaxRetentionSec=30d`, checked over SSH on 2026-10-07. |
| Mail storage | Memo's webmail section says it reads the same database table; other passages and the changelog say MySQL only holds accounts. | [MailStoreUtil.php](../../web/classes/MailStoreUtil.php) reads the Dovecot inbox through `doveadm`; [MailUtil.php](../../web/classes/MailUtil.php) still stores sent copies in `sys_sent`. Changelog wording corrected; source narrative annotated. |
| Hosting | Register describes AWS `us-east-2`/Ohio. | Current [repository instructions](../../AGENTS.md) and [setup README](../../setup-script/README.md) describe native Oracle Cloud Always Free production. This identifies a stale deployment description; it does not establish the current data location or resolve the original legal findings. |
| Git publication | Memo, changelog and AGENTS described GitHub as the Gitea mirror/push target relationship. | REON's actual `home` remote is `https://github.com/zenaror/reon`, changed by the owner on 2026-10-06. Repository instructions and changelog updated. Other repositories' remotes are not inferred from REON's. |
| Net de Get | The source artifact's removed change list ended before the October work. | [NET_DE_GET.md](../NET_DE_GET.md) and changelog record the database, opt-in, admin panel and historical price without billing. Operations now lists its two migrations and account opt-in. |
| Patch build duration | Operations still promised an eight-minute full build despite the later Stadium build and CPU throttle. | Removed that estimate; use each run's journal. Production reports `MemoryHigh=380 MiB`, `MemoryMax=600 MiB`, CPU quota 50%, checked over SSH on 2026-10-07. The pending item for the stale sentence was closed; the separate measurement task remains. |
| Outbound relay provider | Memo names Brevo in the mail investigation. | [RELAY-DE-SAIDA.md](../RELAY-DE-SAIDA.md) describes configurable providers and headers. The historic provider is not a mandatory dependency. |

The deployed retention script's complete SHA-256 differs from the local file;
the checked retention constants agree (the deployed names/comments are in
Portuguese). This review does not claim complete file equivalence or a new
end-to-end mail, hardware, or legal validation.
