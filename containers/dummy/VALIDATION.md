# Dummy server validation — 2026-10-08

The local stack serves the real REON website at `http://192.168.10.183/`.
Public local ports are HTTP 80, POP3 110, SMTP 587 (container 25), and DNS
5453/UDP. No host port 25 is published. All four persistent containers reported
healthy; the migration container exited successfully. Oracle was not changed.

## Application and clean-install gate

`flow-test.py` passed against both the persistent local database and a newly
created, isolated database. The temporary clean-install stack, database volume
and network were removed afterwards. The final stack remains running.

| Flow | Evidence |
| --- | --- |
| Real home and administration | Actual REON PHP pages, normal session and CSRF forms |
| Own account creation | Signup, captured confirmation email, confirmation form, login |
| Adapter configuration | 512 bytes, real MA/LM/DA, valid LM checksum, native SMTP587 flag |
| Internal email | SMTP, external recipient refusal, APOP with device key, exact RETR body including dot/JIS bytes |
| Shared webmail | SMTP to webmail; webmail to POP3; game deletion, web trash and restore |
| Game downloads | Real GB00 challenge/authentication and byte-exact 128-byte diagnostic content |
| Net de Get administration | Real multipart 8192-byte upload, catalog inclusion, opt-in gating, exact download, fixture deletion |
| GB Wars 3 | Seeded Japanese/English map headers and exact English mailbox message bytes |
| Homebrew binary | Mounted file through the actual authenticated `/00/HBREW/` download route |
| DNS | Wildcard A response resolving the game hostname to the LAN address |
| Live web development | Source PHP change visible without rebuilding; complete application gate passed with the overlay |
| Persistence | Previously created account, configuration and mailbox usable after container recreation |

The CGB pattern used by the gate proves storage and transport only; it is not a
playable minigame. Test accounts and messages in the retained database are
synthetic. The gate removes its own published game and homebrew file.

## Natural diagnostic ROM in mGBA

The MAGB diagnostic ROM was operated through its normal menu with joypad input.
No forced CPU dispatch, injected server responses, or socket-connect override
was used for the final runs. The configuration was downloaded from this stack's
real account page; synthetic SRAM supplied that account's ISP password.
Transparent send/receive capture callbacks recorded network traffic.

| Test | Result |
| --- | --- |
| Small buffer | PASS, 128-byte download and upload |
| Big buffer | PASS, 8192-byte download and upload |
| Send mail | PASS, SENT OK; libmobile log confirms native 25 → 587 redirection |
| Receive mail | PASS, mailbox retrieval and deletion through POP3/APOP |

mGBA commit: `3bae8be55b3f4506b8e77602fe343debf9f61940`.
Installed library SHA256: `4ca861c36b9271620665b28988aca89ff3516c3c03bcb70b45a3bddf940b0984`.
Diagnostic ROM SHA256: `cfe6c65ca9e4a0043695af8fd5621ede5b422800bfd462a053b4c3f227f18878`.
Local runner/logs: `$TMPDIR/reon-dummy-emulator/` (this run used `/tmp`).
A credential-free result summary is at `/tmp/reon-dummy-validation.json`.

An earlier native-run attempt was excluded because the synthetic SRAM checksum
was incorrectly constructed; it displayed SET ISP PASSWORD before networking.
The corrected final runs above replace it. Earlier runs with a helper mapping
SMTP sockets to 587 are also superseded by these native runs.

## Boundaries

These results prove the listed application flows and diagnostic ROM protocols.
They do not prove physical Pico Adapter behavior, the original Mobile Trainer
ROM, or arbitrary game payloads. Mobile Trainer's actual HTTP homepage and mail
transport were exercised; the simulated mailbox uses REON's production mail
formatter, preserves game headers, and shares the real account/device database.

The lightweight profile does not include P2P relay, production timers, public
Internet email, TLS, patches or the .NET Pokemon legality checker. Fresh content
comes from repository files/seeds and developer uploads, not Oracle's private
database. The public site's service-status widget has no production checker in
this profile and may show Unknown. These limits are intentional for local use.
CI build/publication has been updated; remote execution is a separate check
and is not covered by these local results.
