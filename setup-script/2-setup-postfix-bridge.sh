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
#   Installs Postfix and makes it the sole listener on port 25, accepting
#   mail for:
#     - the existing game-internal domains (config.json's email_domain_dion,
#       plus the hardcoded gameboy.datacenter.ne.jp) — same accounts, same
#       sys_inbox table, nothing changes from the player's perspective.
#     - a NEW domain you actually own (MAIL_DOMAIN below), so a real address
#       like rafael00@mail.reon.zsrv.com.br can receive real internet mail
#       (e.g. from Gmail) into the exact same mailbox the game reads via
#       POP3 — matched by local part against sys_users.dion_email_local.
#   reon-mail.service's SMTP listener (mail/smtp.js) is disabled via
#   config.json's "disable_smtp" flag (see mail/index.js) since only one
#   process can bind port 25; its POP3 listener (mail/pop3.js) is untouched.
#   Local delivery goes through mail/deliver.js, a small Postfix pipe
#   transport that inserts into sys_inbox exactly like smtp.js used to.
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

# reon-mail.service currently owns port 25 (mail/smtp.js) — free it up before
# installing Postfix so the package's own post-install start doesn't race it.
stop_node_smtp() {
    log_step "Stopping reon-mail.service (freeing port 25 for Postfix)"
    systemctl stop reon-mail.service || true
}

install_packages() {
    log_step "Installing Postfix + certbot"
    debconf-set-selections <<< "postfix postfix/main_mailer_type select Internet Site"
    debconf-set-selections <<< "postfix postfix/mailname string ${MAIL_DOMAIN}"
    apt-get update -qq
    apt_install postfix postfix-mysql postfix-pcre certbot
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
}

configure_postfix_main() {
    log_step "Configuring Postfix (main.cf)"
    postconf -e "myhostname = ${MAIL_DOMAIN}"
    postconf -e "mydestination ="
    postconf -e "virtual_mailbox_domains = ${DION_DOMAIN}, ${GAMEBOY_DOMAIN}, ${MAIL_DOMAIN}"
    postconf -e "virtual_mailbox_maps = mysql:/etc/postfix/mysql-virtual-mailbox.cf"
    postconf -e "virtual_transport = reoninbox"
    postconf -e "smtpd_reject_unlisted_recipient = yes"
    # No relay_domains: anonymous senders can deliver TO the domains above,
    # nothing else — reject_unauth_destination is still the default deny for
    # everything, EXCEPT the one case reon-relay-policy explicitly approves
    # (an authorized device's account sending to a real address) via
    # check_policy_service, which runs first and can only loosen this
    # (answers OK or DUNNO, never REJECT — see relayPolicy.js).
    postconf -e "smtpd_relay_restrictions = permit_mynetworks, check_policy_service inet:127.0.0.1:${RELAY_POLICY_PORT}, reject_unauth_destination"
    # Rejected at SMTP time (sender gets a normal bounce), before it ever
    # reaches deliver.js/sys_inbox. Global, so it also covers the internal
    # dion.ne.jp/gameboy.datacenter.ne.jp domains, but native bottle mail
    # (smtp.js/deliver.js only ever wrote a handful of headers + plain text)
    # is a few hundred bytes at most, nowhere near this — it only ever
    # blocks the newsletter/attachment-bomb case from the real internet
    # side. 15360 = 15KB, generous for a genuinely long personal message,
    # tight enough that anything past it is not something worth spending
    # tens of seconds shipping over the emulated GB Link Cable for.
    postconf -e "message_size_limit = 15360"

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
    # covered by virtual_transport (reoninbox) -- i.e. exactly the same
    # authorized-external case reon-relay-policy already gates, since every
    # local/game-internal domain is already forced onto reoninbox via
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
    log_step "Adding the reoninbox/reonoutbound delivery transports (master.cf)"
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

# BEGIN reoninbox (setup-postfix-bridge.sh): delivers into REON's sys_inbox table.
# No "q" flag: argv is exec'd directly (no shell involved), so there's no
# injection risk to guard against, and "q" would backslash-escape address
# characters before deliver.js ever sees them.
# --jitless: Ubuntu's postfix.service ships with MemoryDenyWriteExecute=yes
# (systemd hardening), inherited by every child Postfix spawns. V8's JIT
# needs W^X memory pages to compile code, which that seccomp filter blocks —
# without --jitless, Node crashes instantly here with a V8 "Check failed:
# 12 == errno" fatal error the moment this transport runs (confirmed: the
# exact same command works fine run standalone, only crashes as a Postfix
# child). --jitless disables the JIT instead of loosening postfix.service's
# hardening, since this script does nothing perf-sensitive anyway.
reoninbox unix  -       n       n       -       -       pipe
  flags=R user=${SYS_USER} argv=${NODE_BIN} --jitless ${OPT_REON}/mail/deliver.js -c ${OPT_REON}/config.json -f \${sender} \${recipient}
# END reoninbox (setup-postfix-bridge.sh)

# BEGIN reonoutbound (setup-postfix-bridge.sh): relays authorized game -> real
# internet mail via mail/outboundRelay.js (default_transport, see
# configure_outbound_relay_transport()) instead of Postfix's own smtp(8)
# client, so the ISO-2022-JP body can be decoded before Brevo ever sees it.
reonoutbound unix  -       n       n       -       -       pipe
  flags=R user=${SYS_USER} argv=${NODE_BIN} --jitless ${OPT_REON}/mail/outboundRelay.js -c ${OPT_REON}/config.json -f \${sender} \${recipient}
# END reonoutbound (setup-postfix-bridge.sh)
EOF
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
    log_step "Disabling mail/smtp.js and restarting reon-mail.service (POP3 only)"
    cp -n "$REON_SRC/config.json" "$REON_SRC/config.json.bak"
    json_set_bool "$REON_SRC/config.json" disable_smtp true
    systemctl start reon-mail.service
    systemctl --no-pager --lines=0 status reon-mail.service
}

start_postfix() {
    log_step "Starting Postfix"
    systemctl enable postfix
    systemctl restart postfix
    systemctl --no-pager --lines=0 status postfix
}

install_log_commands() {
    log_step "Adding Postfix to reon-status / reon-logs-all"
    # Patches the already-deployed reon-status/reon-logs-all in place
    # (setup-reon.sh owns generating them from scratch, so this only ever
    # injects into its output rather than duplicating its logic here). Not
    # persistent across a setup-reon.sh re-run -- that regenerates both
    # files from its own template, without Postfix, so re-run this script
    # afterward to patch them back in.
    local status_src logs_all_src
    status_src="$(readlink -f /usr/local/bin/reon-status 2>/dev/null || true)"
    logs_all_src="$(readlink -f /usr/local/bin/reon-logs-all 2>/dev/null || true)"

    if [[ -n "$status_src" ]] && ! grep -q "postfix" "$status_src"; then
        sed -i 's/reon-mobile-relay nginx dnsmasq mysql \\/reon-mobile-relay nginx dnsmasq mysql postfix \\/' "$status_src"
    fi
    if [[ -n "$status_src" ]] && ! grep -q "reon-relay-policy" "$status_src"; then
        sed -i 's/reon-mobile-relay /reon-mobile-relay reon-relay-policy /' "$status_src"
    fi
    if [[ -n "$logs_all_src" ]] && ! grep -q "postfix.service" "$logs_all_src"; then
        sed -i 's/-u mysql.service/-u mysql.service -u postfix.service/' "$logs_all_src"
    fi
    if [[ -n "$logs_all_src" ]] && ! grep -q "reon-relay-policy.service" "$logs_all_src"; then
        sed -i 's/-u mysql.service/-u mysql.service -u reon-relay-policy.service/' "$logs_all_src"
    fi

    # Also a standalone one, same convention as reon-logs-mail/reon-logs-web.
    cat > /usr/local/bin/reon-logs-postfix <<'EOF'
#!/usr/bin/env bash
exec journalctl -u postfix.service -f
EOF
    chmod +x /usr/local/bin/reon-logs-postfix

    cat > /usr/local/bin/reon-logs-relay-policy <<'EOF'
#!/usr/bin/env bash
exec journalctl -u reon-relay-policy.service -f
EOF
    chmod +x /usr/local/bin/reon-logs-relay-policy
    log_info "reon-status / reon-logs-all now include Postfix and reon-relay-policy — reon-logs-postfix / reon-logs-relay-policy also available"
}

print_summary() {
    log_step "Done"
    log_info "Postfix now owns port 25 for: ${DION_DOMAIN}, ${GAMEBOY_DOMAIN}, ${MAIL_DOMAIN}"
    log_info "reon-mail.service now only serves POP3 (:110) — the game is unaffected."
    echo
    log_info "DNS records still needed at your registrar for zsrv.com.br, if not already set:"
    echo "    ${MAIL_DOMAIN}.  A   ${EXTERNAL_IP:-<this servers public IP>}"
    echo "    ${MAIL_DOMAIN}.  MX  10 ${MAIL_DOMAIN}."
    echo
    log_info "Once that resolves: re-run this script to pick up the TLS cert, then test with:"
    echo "    echo test | mail -s hello rafael00@${MAIL_DOMAIN}   # from any real mail account"
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
    configure_postfix_master
    request_tls_cert
    configure_postfix_main
    configure_outbound_relay_transport
    deploy_relay_policy_service
    disable_node_smtp_and_restart
    start_postfix
    install_log_commands
    print_summary
}

main "$@"
