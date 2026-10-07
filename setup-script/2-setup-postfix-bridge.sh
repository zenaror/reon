#!/usr/bin/env bash
if [ -z "${BASH_VERSION:-}" ]; then
    exec bash "$0" "$@"
fi
#
# setup-postfix-bridge.sh — Real-internet inbound email bridge for REON
#
# Companion to setup-reon.sh (must be run after it, on the same host).
#
# What this does:
#   Installs Postfix and Dovecot and makes them the mail system, as REONTeam's
#   own server has it:
#     - Postfix is the sole listener on port 25 and accepts mail for the
#       game-internal domains (config.json's email_domain_dion, plus the
#       hardcoded gameboy.datacenter.ne.jp) and for a domain you actually own
#       (MAIL_DOMAIN below), so a real address like player01@mail.reon.zsrv.com.br
#       can receive real internet mail (e.g. from Gmail) into the very mailbox
#       the game reads. Recipients are matched by local part against
#       sys_users.dion_email_local (an alias also maps the account name).
#     - Postfix hands the accepted mail to Dovecot over LMTP; Dovecot keeps it in
#       Maildir under /var/vmail (one virtual user, vmail) and runs a Sieve
#       filter that shapes it for the Game Boy AT DELIVERY (mail/gameFormat.js).
#     - Dovecot answers port 110 for the game (APOP with the device-auth key)
#       and the webmail reads through doveadm; MySQL keeps only the accounts.
#       See examples/dovecot/README.md.
#     - A banned account is refused at every door, this one included: Dovecot's
#       password query returns '*' for it, and Postfix rejects it as a sender
#       (mysql-banned-sender.cf, our own domains only, local jobs exempt).
#   reon-mail.service keeps no mail listener of its own here: config.json gets
#   disable_pop3 and shaped_at_delivery (see mail/index.js) and mail_store = dovecot.
#   It still runs the small side-effects service the Sieve filter calls (the
#   Sent copy and the notification bell).
#
# Also installs the outbound side: a device-auth-gated Postfix policy
# service (mail/relayPolicy.js, systemd unit reon-relay-policy.service) that
# lets a RCPT TO a real address through only while the sending account's
# device is authorized (sys_device_counter.authorized, per device, the same flag
# /api/adapter/device-auth already maintains) — anything else still gets
# reject_unauth_destination, unchanged. Whatever it approves is relayed via
# Brevo (the same relay config.json's own transactional mail already uses)
# rather than attempting direct-to-MX delivery, so it doesn't depend on
# reon.dion.ne.jp/mail.reon.zsrv.com.br having any sender reputation of
# their own.
#
# What this does NOT do:
#   - No DKIM signing of its own — outbound mail relays through Brevo, which
#     handles that on its own domain.
#
# Idempotent: safe to re-run after DNS/cert changes.
#
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Same two layouts as 1-setup-reon.sh: packaged (reon/ next to the script)
# or repository (this script inside reon/setup-script/).
if [[ -d "$SCRIPT_DIR/reon" ]]; then
    REON_SRC="$SCRIPT_DIR/reon"
else
    REON_SRC="$(cd "$SCRIPT_DIR/.." && pwd)"
fi
OPT_REON=/opt/reon
SYS_USER=reon
NODE_BIN=/opt/node/bin/node
GAMEBOY_DOMAIN="gameboy.datacenter.ne.jp"
RELAY_POLICY_PORT="10045"

# The real, self-owned domain that bridges to the outside world. Override
# with: MAIL_DOMAIN=something.else sudo -E ./setup-postfix-bridge.sh
MAIL_DOMAIN="${MAIL_DOMAIN:-mail.reon.zsrv.com.br}"

c_reset=$'\033[0m'; c_red=$'\033[31m'; c_green=$'\033[32m'; c_yellow=$'\033[33m'; c_blue=$'\033[34m'
log_step()  { printf '\n%s==>%s %s\n' "$c_blue"  "$c_reset" "$*"; }
log_info()  { printf '%s[info]%s %s\n'  "$c_green"  "$c_reset" "$*"; }
log_warn()  { printf '%s[warn]%s %s\n'  "$c_yellow" "$c_reset" "$*" >&2; }
log_error() { printf '%s[error]%s %s\n' "$c_red"    "$c_reset" "$*" >&2; }

trap 'log_error "Setup failed at line $LINENO."' ERR

json_get() {
    python3 - "$1" "$2" "${3:-}" <<'PYEOF'
import json, sys
path, key, default = sys.argv[1], sys.argv[2], sys.argv[3]
with open(path) as f:
    data = json.load(f)
print(data.get(key, default))
PYEOF
}

json_set_str() {
    python3 - "$1" "$2" "$3" <<'PYEOF'
import json, sys
path, key, value = sys.argv[1], sys.argv[2], sys.argv[3]
with open(path) as f:
    data = json.load(f)
data[key] = value
with open(path, "w") as f:
    json.dump(data, f, indent="\t")
    f.write("\n")
PYEOF
}

json_set_bool() {
    python3 - "$1" "$2" "$3" <<'PYEOF'
import json, sys
path, key, value = sys.argv[1], sys.argv[2], sys.argv[3]
with open(path) as f:
    data = json.load(f)
data[key] = (value == "true")
with open(path, "w") as f:
    json.dump(data, f, indent="\t")
    f.write("\n")
PYEOF
}

apt_install() {
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "$@"
}

load_env_file() {
    local file="$1"
    [[ -f "$file" ]] || return 0
    while IFS='=' read -r key val; do
        [[ -z "$key" || "$key" == \#* ]] && continue
        export "$key=$val"
    done < <(grep -v '^[[:space:]]*#' "$file" | grep -v '^[[:space:]]*$')
}

require_root() {
    if [[ "${EUID}" -ne 0 ]]; then
        log_error "This script must be run as root (e.g. sudo ./setup-postfix-bridge.sh)."
        exit 1
    fi
}

require_base_setup() {
    log_step "Checking prerequisites"
    if [[ ! -L "$OPT_REON" ]]; then
        log_error "$OPT_REON doesn't exist yet — run setup-reon.sh first."
        exit 1
    fi
    if ! systemctl list-unit-files reon-mail.service >/dev/null 2>&1; then
        log_error "reon-mail.service isn't installed — run setup-reon.sh first."
        exit 1
    fi
    if [[ ! -x "$NODE_BIN" ]]; then
        log_error "$NODE_BIN not found — run setup-reon.sh first."
        exit 1
    fi
    log_info "Bridge domain: $MAIL_DOMAIN"
    load_env_file "$REON_SRC/.env"
}

load_config() {
    DION_DOMAIN="$(json_get "$REON_SRC/config.json" email_domain_dion)"
    MYSQL_HOST="$(json_get "$REON_SRC/config.json" mysql_host)"
    MYSQL_USER="$(json_get "$REON_SRC/config.json" mysql_user)"
    MYSQL_PASSWORD="$(json_get "$REON_SRC/config.json" mysql_password)"
    MYSQL_DATABASE="$(json_get "$REON_SRC/config.json" mysql_database)"
    BREVO_HOST="$(json_get "$REON_SRC/config.json" smtp_host)"
    BREVO_PORT="$(json_get "$REON_SRC/config.json" smtp_port)"
    BREVO_USER="$(json_get "$REON_SRC/config.json" smtp_user)"
    BREVO_PASS="$(json_get "$REON_SRC/config.json" smtp_pass)"
    log_info "Game-internal domain: $DION_DOMAIN (unchanged, still emulator-only)"
}

# reon-mail.service may still hold port 25 (mail/smtp.js on an old install) — free it up before
# installing Postfix so the package's own post-install start doesn't race it.
stop_node_smtp() {
    log_step "Stopping reon-mail.service (freeing port 25 for Postfix)"
    systemctl stop reon-mail.service || true
}

install_packages() {
    log_step "Installing Postfix, Dovecot + certbot"
    debconf-set-selections <<< "postfix postfix/main_mailer_type select Internet Site"
    debconf-set-selections <<< "postfix postfix/mailname string ${MAIL_DOMAIN}"
    apt-get update -qq
    apt_install postfix postfix-mysql certbot \
        dovecot-core dovecot-imapd dovecot-lmtpd dovecot-mysql dovecot-pop3d dovecot-sieve
}

configure_mysql_map() {
    log_step "Writing Postfix's MySQL recipient map"
    # Any non-empty result = valid local part, regardless of which of the
    # three domains it arrived at (dion.ne.jp / gameboy.datacenter.ne.jp /
    # MAIL_DOMAIN all share the same sys_users.dion_email_local namespace).
    cat > /etc/postfix/mysql-virtual-mailbox.cf <<EOF
# Generated by setup-postfix-bridge.sh
user = ${MYSQL_USER}
password = ${MYSQL_PASSWORD}
hosts = ${MYSQL_HOST}
dbname = ${MYSQL_DATABASE}
query = SELECT 1 FROM sys_users WHERE dion_email_local='%u' LIMIT 1
EOF
    chown root:postfix /etc/postfix/mysql-virtual-mailbox.cf
    chmod 640 /etc/postfix/mysql-virtual-mailbox.cf

    # O nome de conta como apelido do nome da caixa.
    #
    # Uma conta tem DOIS nomes -- `username` e `dion_email_local` -- e o site
    # anuncia os dois como endereço da pessoa: a página da conta mostra
    # `username@MAIL_DOMAIN` como principal, e o e-mail de boas-vindas repete.
    # O mapa acima, porém, conhece só `dion_email_local`. Sem este apelido,
    # o endereço anunciado como principal responde "550 User unknown in
    # virtual mailbox table" -- ou seja, o serviço assina cartas com um
    # endereço que ele mesmo não sabe receber.
    #
    # Reescreve para o nome da caixa MANTENDO o domínio (%d), para que a
    # entrega caia na caixa que já existe em vez de abrir uma segunda. A
    # condição `dion_email_local <> '%u'` evita mapear um endereço para ele
    # mesmo nas contas em que os dois nomes coincidem.
    cat > /etc/postfix/mysql-virtual-alias.cf <<EOF
# Generated by setup-postfix-bridge.sh -- apelido do nome de conta
user = ${MYSQL_USER}
password = ${MYSQL_PASSWORD}
hosts = ${MYSQL_HOST}
dbname = ${MYSQL_DATABASE}
query = SELECT CONCAT(dion_email_local, '@', '%d') FROM sys_users WHERE username = '%u' AND dion_email_local <> '%u' LIMIT 1
EOF
    chown root:postfix /etc/postfix/mysql-virtual-alias.cf
    chmod 640 /etc/postfix/mysql-virtual-alias.cf

    # Conta banida não manda correio do jogo. O SMTP do jogo não autentica
    # nada, então a única identidade que existe é o endereço do remetente, e é
    # por ele que se recusa. Só nos nossos domínios: um remetente de fora cujo
    # nome coincida com o de uma conta banida não pode ser barrado por isso.
    cat > /etc/postfix/mysql-banned-sender.cf <<EOF
# Generated by setup-postfix-bridge.sh -- remetente de conta banida
user = ${MYSQL_USER}
password = ${MYSQL_PASSWORD}
hosts = ${MYSQL_HOST}
dbname = ${MYSQL_DATABASE}
query = SELECT 'REJECT Account suspended' FROM sys_users WHERE banned_at IS NOT NULL AND '%d' IN ('${DION_DOMAIN}', '${GAMEBOY_DOMAIN}', '${MAIL_DOMAIN}') AND (dion_email_local = '%u' OR username = '%u') LIMIT 1
EOF
    chown root:postfix /etc/postfix/mysql-banned-sender.cf
    chmod 640 /etc/postfix/mysql-banned-sender.cf
}

# Dovecot: mailbox storage, LMTP, the Sieve filter and the game's POP3 (:110).
# Everything here is idempotent: re-running rewrites the same files and keeps
# the doveadm password an earlier run generated.
configure_dovecot() {
    log_step "Configuring Dovecot (Maildir, LMTP, Sieve filter, POP3 on :110)"
    local src="$REON_SRC/examples/dovecot"

    # One virtual user owns every mailbox (see the header of 99-reon.conf).
    getent group vmail >/dev/null || groupadd --system --gid 5000 vmail
    getent passwd vmail >/dev/null || useradd --system --uid 5000 --gid vmail \
        --home-dir /var/vmail --shell /usr/sbin/nologin vmail
    mkdir -p /var/vmail
    chown vmail:vmail /var/vmail
    chmod 0770 /var/vmail

    # The system (PAM/passwd) passdb that ships enabled is not used and must
    # not be a second way in, so it is commented out; ours is the only one.
    if [[ -f /etc/dovecot/conf.d/10-auth.conf ]]; then
        sed -i 's/^\(!include auth-system\.conf\.ext\)/#\1/' /etc/dovecot/conf.d/10-auth.conf
    fi

    install -m 0644 "$src/99-reon.conf" /etc/dovecot/conf.d/99-reon.conf

    # Credentials: the database login and the doveadm-server password. Group
    # `reon`, because doveadm runs as the mail service's user and reads the
    # whole configuration as whoever calls it. The password is generated once
    # and kept on re-runs.
    local doveadm_pass=""
    if [[ -f /etc/dovecot/dovecot-sql.conf.ext ]]; then
        doveadm_pass="$(sed -n 's/^doveadm_password = //p' /etc/dovecot/dovecot-sql.conf.ext | head -1)"
    fi
    [[ -n "$doveadm_pass" && "$doveadm_pass" != "TROQUE_ME" ]] || \
        doveadm_pass="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 32)"
    cat > /etc/dovecot/dovecot-sql.conf.ext <<EOF
# Generated by setup-postfix-bridge.sh -- do not edit by hand.
# Readable by group ${SYS_USER} (the mail service's user): doveadm reads the whole
# configuration as whoever invokes it. Not a new exposure -- it is the MySQL
# password reon already has in ${OPT_REON}/config.json.
mysql /var/run/mysqld/mysqld.sock {
  user = ${MYSQL_USER}
  password = ${MYSQL_PASSWORD}
  dbname = ${MYSQL_DATABASE}
}

# Authenticates the doveadm client (the webmail, the admin panel, the jobs)
# to the doveadm-server socket.
doveadm_password = ${doveadm_pass}
EOF
    chown root:"${SYS_USER}" /etc/dovecot/dovecot-sql.conf.ext
    chmod 0640 /etc/dovecot/dovecot-sql.conf.ext

    # What the APOP challenge announces after the "@" (see examples/dovecot/README.md).
    mkdir -p /etc/systemd/system/dovecot.service.d
    cat > /etc/systemd/system/dovecot.service.d/hostname.conf <<EOF
# Generated by setup-postfix-bridge.sh
[Service]
Environment=DOVECOT_HOSTNAME=${MAIL_DOMAIN}
EOF

    # The delivery filter: Sieve calls ${OPT_REON}/mail/sieve-filters/reon-delivery,
    # which runs mail/gameFormat.js. The script decides "internal or external"
    # from the sender's domain, and the two domains in it are the same pair as
    # email_domain / email_domain_dion in config.json -- kept in the script and
    # not read from config.json, so the mail user never opens the credentials.
    mkdir -p /etc/dovecot/sieve
    sed -e "s/reon\.dion\.ne\.jp/${DION_DOMAIN//./\\.}/g" \
        -e "s/mail\.reon\.zsrv\.com\.br/${MAIL_DOMAIN//./\\.}/g" \
        "$src/reon-delivery.sieve" > /etc/dovecot/sieve/reon-delivery.sieve
    chmod +x "${OPT_REON}/mail/sieve-filters/reon-delivery" 2>/dev/null || true
    sievec /etc/dovecot/sieve/reon-delivery.sieve 2>/dev/null || \
        log_warn "sievec could not compile the delivery filter; Dovecot will retry on first delivery."

    if ! doveconf -n >/dev/null 2>&1; then
        log_error "Dovecot's configuration does not parse (doveconf -n); see the message above."
        exit 1
    fi
    systemctl daemon-reload
    systemctl enable dovecot
    systemctl restart dovecot
    systemctl --no-pager --lines=0 status dovecot
}

configure_postfix_main() {
    log_step "Configuring Postfix (main.cf)"
    postconf -e "myhostname = ${MAIL_DOMAIN}"
    postconf -e "mydestination ="
    postconf -e "virtual_mailbox_domains = ${DION_DOMAIN}, ${GAMEBOY_DOMAIN}, ${MAIL_DOMAIN}"
    postconf -e "virtual_mailbox_maps = mysql:/etc/postfix/mysql-virtual-mailbox.cf"
    postconf -e "virtual_alias_maps = mysql:/etc/postfix/mysql-virtual-alias.cf"
    # Explícito e vazio: por padrão `virtual_alias_domains` vale
    # `$virtual_alias_maps`, e um domínio não pode ser de alias e de caixa ao
    # mesmo tempo. Aqui todos os domínios são de caixa; o mapa acima só
    # reescreve a parte local.
    postconf -e "virtual_alias_domains ="
    # LMTP to Dovecot (the socket 99-reon.conf opens inside Postfix's chroot).
    # This used to be a pipe transport, reoninbox, that wrote each message into
    # the sys_inbox table through mail/deliver.js; that table is gone.
    postconf -e "virtual_transport = lmtp:unix:private/dovecot-lmtp"
    postconf -e "smtpd_reject_unlisted_recipient = yes"
    # No relay_domains: anonymous senders can deliver TO the domains above,
    # nothing else — reject_unauth_destination is still the default deny for
    # everything, EXCEPT the one case reon-relay-policy explicitly approves
    # (an authorized device's account sending to a real address) via
    # check_policy_service, which runs first and can only loosen this
    # (answers OK or DUNNO, never REJECT — see relayPolicy.js).
    postconf -e "smtpd_relay_restrictions = permit_mynetworks, check_policy_service inet:127.0.0.1:${RELAY_POLICY_PORT}, reject_unauth_destination"
    # Remetente banido: recusado no SMTP. permit_mynetworks vem antes para que
    # os jobs locais (Trade Corner, Mail de Cute), que mandam em nome dos
    # jogadores, nunca sejam barrados por causa de um deles.
    postconf -e "smtpd_sender_restrictions = permit_mynetworks, check_sender_access mysql:/etc/postfix/mysql-banned-sender.cf"
    # Rejected at SMTP time (sender gets a normal bounce), before it ever
    # reaches Dovecot. Global, so it also covers the internal
    # dion.ne.jp/gameboy.datacenter.ne.jp domains, but native bottle mail
    # (the games only ever write a handful of headers + plain text)
    # is a few hundred bytes at most, nowhere near this — it only ever
    # blocks the newsletter/attachment-bomb case from the real internet
    # side. 15360 = 15KB, generous for a genuinely long personal message,
    # tight enough that anything past it is not something worth spending
    # tens of seconds shipping over the emulated GB Link Cable for.
    postconf -e "message_size_limit = 15360"
    # Postfix's own smtp(8) client (bounces and the like; the game's outbound mail
    # goes through reonoutbound and Brevo instead) never speaks in the clear.
    postconf -e "smtp_tls_security_level = encrypt"

    local cert_dir="/etc/letsencrypt/live/${MAIL_DOMAIN}"
    if [[ -f "$cert_dir/fullchain.pem" ]]; then
        postconf -e "smtpd_tls_cert_file = ${cert_dir}/fullchain.pem"
        postconf -e "smtpd_tls_key_file = ${cert_dir}/privkey.pem"
        postconf -e "smtpd_tls_security_level = may"
        log_info "TLS enabled (opportunistic) using existing cert for ${MAIL_DOMAIN}."
    else
        log_warn "No TLS cert yet for ${MAIL_DOMAIN} — Postfix will run without TLS for now."
        log_warn "Re-run this script after DNS + request_tls_cert() succeed to pick it up."
    fi
}

configure_outbound_relay_transport() {
    log_step "Routing outbound relay through mail/outboundRelay.js"
    if [[ -z "$BREVO_HOST" || "$BREVO_HOST" == "None" ]]; then
        log_warn "No smtp_host in config.json — outboundRelay.js will refuse to send."
        log_warn "reon-relay-policy can still approve a message, but delivery will then"
        log_warn "keep failing (tempfail) until smtp_host is configured."
    fi
    # default_transport (not relayhost/smtp(8)) now handles everything not
    # covered by virtual_transport (Dovecot's LMTP) -- i.e. exactly the same
    # authorized-external case reon-relay-policy already gates, since every
    # local/game-internal domain is already delivered to Dovecot via
    # virtual_mailbox_domains. Routing this through our own pipe transport
    # (instead of Postfix's built-in smtp(8) client + relayhost) is what
    # lets outboundRelay.js decode the game's ISO-2022-JP body to real UTF-8
    # before Brevo ever sees it -- smtp_generic_maps/smtp_header_checks could
    # only ever rewrite headers, never the body itself. outboundRelay.js
    # does the domain/From-header rewrite inline now too (see its own
    # comments), so relayhost/smtp_generic_maps/smtp_header_checks/SASL are
    # no longer used for this path at all.
    postconf -e "default_transport = reonoutbound"
    # Clean up settings from the old relayhost/smtp(8)-client approach this
    # replaces -- harmless left in place (nothing routes through the smtp(8)
    # client for outbound-external mail anymore), but stale and misleading
    # for anyone reading `postconf -n` later. `postconf -X` on a parameter
    # that was never set is a no-op, so this is safe on a fresh install too.
    postconf -X relayhost smtp_sasl_auth_enable smtp_sasl_password_maps \
        smtp_sasl_security_options smtp_generic_maps smtp_header_checks \
        2>/dev/null || true
    rm -f /etc/postfix/sasl_passwd /etc/postfix/sasl_passwd.db \
        /etc/postfix/generic /etc/postfix/generic.db \
        /etc/postfix/smtp_header_checks
    log_info "Outbound relay now routes through mail/outboundRelay.js -> ${BREVO_HOST:-<unset>}:${BREVO_PORT:-<unset>}"
}

deploy_relay_policy_service() {
    log_step "Installing reon-relay-policy.service"
    cat > /etc/systemd/system/reon-relay-policy.service <<EOF
[Unit]
Description=REON Outbound Relay Policy (Postfix policy delegation)
Documentation=https://github.com/REONTeam/reon
After=network.target mysql.service
Requires=mysql.service

[Service]
Type=simple
User=${SYS_USER}
WorkingDirectory=${OPT_REON}/mail
ExecStart=${NODE_BIN} ${OPT_REON}/mail/relayPolicy.js -c ${OPT_REON}/config.json -p ${RELAY_POLICY_PORT}
Restart=on-failure
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    systemctl enable --now reon-relay-policy.service
    systemctl --no-pager --lines=0 status reon-relay-policy.service
}

configure_postfix_master() {
    log_step "Adding the reonoutbound transport to master.cf (and dropping the old reoninbox one)"
    # reoninbox (the pipe transport into sys_inbox) is not written any more; the
    # removal below is what takes it out of a master.cf that still has it, so
    # upgrading an old install leaves no dead transport behind.
    #
    # Remove any previous copy of these blocks before appending fresh ones,
    # so re-running this script (e.g. to pick up a fix like --jitless below)
    # replaces them instead of leaving a stale copy, silently skipping past
    # it, or — worst case — appending a duplicate service name that makes
    # Postfix refuse to start. Handles both the current marked format and
    # the older single-comment-line format (from before these BEGIN/END
    # markers existed), since a master.cf out in the wild may still have
    # that older copy, possibly hand-patched since.
    sed -i \
        -e '/^# BEGIN reoninbox (setup-postfix-bridge.sh)/,/^# END reoninbox (setup-postfix-bridge.sh)/d' \
        -e '/^# BEGIN reonoutbound (setup-postfix-bridge.sh)/,/^# END reonoutbound (setup-postfix-bridge.sh)/d' \
        -e '/^# Added by setup-postfix-bridge.sh: delivers into REON/d' \
        /etc/postfix/master.cf
    awk '
        /^reoninbox[[:space:]]+unix/ { skip=1; next }
        /^reonoutbound[[:space:]]+unix/ { skip=1; next }
        skip && /^[[:space:]]/         { next }
        { skip=0; print }
    ' /etc/postfix/master.cf > /etc/postfix/master.cf.new
    mv /etc/postfix/master.cf.new /etc/postfix/master.cf
    cat >> /etc/postfix/master.cf <<EOF

# BEGIN reonoutbound (setup-postfix-bridge.sh): relays authorized game -> real
# internet mail via mail/outboundRelay.js (default_transport, see
# configure_outbound_relay_transport()) instead of Postfix's own smtp(8)
# client, so the ISO-2022-JP body can be decoded before Brevo ever sees it.
# No "q" flag: argv is exec'd directly (no shell involved), so there is no
# injection risk to guard against, and "q" would backslash-escape address
# characters before the program ever sees them.
# --jitless: Ubuntu's postfix.service ships with MemoryDenyWriteExecute=yes
# (systemd hardening), inherited by every child Postfix spawns. V8's JIT
# needs W^X memory pages to compile code, which that seccomp filter blocks --
# without --jitless, Node crashes instantly here with a V8 "Check failed:
# 12 == errno" fatal error the moment this transport runs. --jitless disables
# the JIT instead of loosening postfix.service's hardening, since this script
# does nothing perf-sensitive anyway.
reonoutbound unix  -       n       n       -       -       pipe
  flags=R user=${SYS_USER} argv=${NODE_BIN} --jitless ${OPT_REON}/mail/outboundRelay.js -c ${OPT_REON}/config.json -f \${sender} \${recipient}
# END reonoutbound (setup-postfix-bridge.sh)
EOF
}

# A renewed certificate is only picked up when Postfix reloads; certbot renews by
# itself, so the reload is a deploy hook (which runs on renewal, not on every
# certbot invocation).
install_cert_reload_hook() {
    mkdir -p /etc/letsencrypt/renewal-hooks/deploy
    cat > /etc/letsencrypt/renewal-hooks/deploy/reon-mail-reload.sh <<'EOF'
#!/bin/sh
# Generated by 2-setup-postfix-bridge.sh
systemctl reload postfix
EOF
    chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/reon-mail-reload.sh
}

request_tls_cert() {
    log_step "Requesting a Let's Encrypt certificate for ${MAIL_DOMAIN}"
    if [[ -f "/etc/letsencrypt/live/${MAIL_DOMAIN}/fullchain.pem" ]]; then
        log_info "Certificate already present."
        return
    fi
    log_info "This needs ${MAIL_DOMAIN}'s DNS A record already pointing at this VM's public IP,"
    log_info "and nginx (from setup-reon.sh) serving it — both already true if DNS is set."

    local email_args=(--register-unsafely-without-email)
    if [[ -n "${LETSENCRYPT_EMAIL:-}" ]]; then
        email_args=(--email "$LETSENCRYPT_EMAIL")
    fi

    if certbot certonly --webroot -w "$OPT_REON/web/htdocs" -d "$MAIL_DOMAIN" \
        --non-interactive --agree-tos "${email_args[@]}"; then
        log_info "Certificate obtained for ${MAIL_DOMAIN}."
    else
        log_warn "Could not obtain a certificate yet — DNS for ${MAIL_DOMAIN} may not have propagated."
        log_warn "Postfix will still work over plaintext; re-run this script once DNS resolves."
    fi
}

disable_node_smtp_and_restart() {
    log_step "Handing mail over to Postfix/Dovecot in config.json and restarting reon-mail.service"
    cp -n "$REON_SRC/config.json" "$REON_SRC/config.json.bak"
    json_set_bool "$REON_SRC/config.json" disable_pop3 true
    json_set_bool "$REON_SRC/config.json" shaped_at_delivery true
    json_set_str  "$REON_SRC/config.json" mail_store dovecot
    systemctl start reon-mail.service
    systemctl --no-pager --lines=0 status reon-mail.service
}

start_postfix() {
    log_step "Starting Postfix (Dovecot is already running: it owns the LMTP socket Postfix delivers to)"
    systemctl enable postfix
    systemctl restart postfix
    systemctl --no-pager --lines=0 status postfix
}

print_summary() {
    log_step "Done"
    log_info "Postfix now owns port 25 for: ${DION_DOMAIN}, ${GAMEBOY_DOMAIN}, ${MAIL_DOMAIN}"
    log_info "Dovecot now answers POP3 on :110 for the game (APOP); reon-mail.service runs no mail listener."
    echo
    log_info "DNS records still needed at your registrar for zsrv.com.br, if not already set:"
    echo "    ${MAIL_DOMAIN}.  A   ${EXTERNAL_IP:-<this servers public IP>}"
    echo "    ${MAIL_DOMAIN}.  MX  10 ${MAIL_DOMAIN}."
    echo
    log_info "Once that resolves: re-run this script to pick up the TLS cert, then test with:"
    echo "    send a message to player01@${MAIL_DOMAIN} from any real mail account (Gmail, ...)"
    echo
    log_info "Outbound (game -> real internet) is enabled, gated on device-auth:"
    log_info "  reon-relay-policy.service only lets a RCPT to a real address through while"
    log_info "  the sending account's device is currently authorized; relayed via Brevo."
}

main() {
    require_root
    require_base_setup
    load_config
    stop_node_smtp
    install_packages
    configure_mysql_map
    configure_dovecot
    configure_postfix_master
    install_cert_reload_hook
    request_tls_cert
    configure_postfix_main
    configure_outbound_relay_transport
    deploy_relay_policy_service
    disable_node_smtp_and_restart
    start_postfix
    print_summary
}

main "$@"
