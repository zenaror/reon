# REON Dovecot configuration

`setup-script/2-setup-postfix-bridge.sh` installs this configuration through
`configure_dovecot`: packages, the `vmail` user, `99-reon.conf`, SQL settings
rendered from private `config.json`, a once-generated doveadm password, the APOP
hostname drop-in and the Sieve delivery filter using configured mail domains.
The installer supports rerunning the configuration step.

Manual destination equivalents:

```text
99-reon.conf              -> /etc/dovecot/conf.d/99-reon.conf
dovecot-sql.conf.ext      -> /etc/dovecot/dovecot-sql.conf.ext (root:reon 0640)
reon-delivery.sieve       -> /etc/dovecot/sieve/reon-delivery.sieve (then sievec)
```

Only the `.example` SQL configuration belongs in Git; the rendered file contains
plaintext credentials and stays on the server. Group `reon` is the mail service
user group because doveadm reads configuration with its invoking identity.

## Storage and authentication

Dovecot owns mailbox storage and production POP3 port 110. MySQL stores account
registration; Postfix delivers over LMTP and webmail reads through the privileged
doveadm socket. The optional Node POP3 listener is disabled with `disable_pop3`.

APOP and CRAM-MD5 use the 32-byte device key represented as 64 hexadecimal ASCII
characters. `plain`/`login` with the eight-character game password are controlled
by `sys_settings.pop3_password_fallback`: the repository default is enabled;
Oracle was observed with it disabled by operator policy. Check the live setting
rather than inferring current state from this document.

When fallback is disabled, a key is missing or an account is banned, the affected
authentication query returns `*` as a nonmatching password. Keep the user row:
doveadm uses the same lookup and omitting it would also make webmail unreadable.

## Ports

- **110:** public Dovecot POP3 used by game clients.
- **10143:** development IMAP. Dovecot 2.4 uses a global listening address, so the
  firewall must prevent public access to this port.

## `dovecot-hostname.conf.example`

The host name the APOP challenge announces after the `@`. Install it as
`/etc/systemd/system/dovecot.service.d/hostname.conf`, then
`systemctl daemon-reload && systemctl restart dovecot`.

Without it Dovecot falls back to `gethostname()`, which on a cloud instance
is the instance's own name — handed to anyone who opens a POP3 connection,
before any login.

It only works from the environment: `hostname` in the Dovecot config governs
a different field, `DOVECOT_HOSTDOMAIN` governs the domain, and the
`import_environment` block in `99-reon.conf` only lets the value through to
the child processes. A reload does not re-send the environment, so this needs
a restart — the configuration reads correctly while the greeting stays wrong.
