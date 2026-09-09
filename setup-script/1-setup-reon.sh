#!/usr/bin/env bash
# If this ever gets invoked under a non-bash /bin/sh (dash doesn't support
# [[, arrays, etc. — e.g. corrupted shebang, or someone ran `sh setup-reon.sh`
# directly), transparently re-exec ourselves under bash instead of failing
# with cryptic "[[: not found" errors. Written in plain POSIX so it parses
# correctly under dash too.
if [ -z "${BASH_VERSION:-}" ]; then
    exec bash "$0" "$@"
fi
#
# setup-reon.sh — Native (non-Docker) installer for REON + mobile-relay
#
# Designed for a low-memory Oracle Cloud "Always Free" Ubuntu VM where running
# both docker-compose stacks at once was overloading the box.
#
# This script installs everything directly on the host and wires the two
# projects together with systemd units + nginx + php-fpm + MySQL + dnsmasq,
# using symlinks everywhere so that updating the app later is just:
#   cd <this checkout> && git pull && sudo bash setup-script/1-setup-reon.sh
# (re-running is idempotent; it will only reinstall/rebuild what changed).
#
# MUST be run as root, ON THE TARGET VM (not on a dev machine). Two layouts
# are understood:
#   - this script inside the reon checkout, at reon/setup-script/, with the
#     mobile-relay checkout next to reon/ (the layout of the repositories);
#   - this script next to `reon/` and `mobile-relay/` folders (the layout of
#     the packaged folder).
#
set -Eeuo pipefail

# ---------------------------------------------------------------------------
# Globals
# ---------------------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -d "$SCRIPT_DIR/reon" && -d "$SCRIPT_DIR/mobile-relay" ]]; then
    # Packaged layout: reon/ and mobile-relay/ next to the script.
    REON_SRC="$SCRIPT_DIR/reon"
    RELAY_SRC="$SCRIPT_DIR/mobile-relay"
else
    # Repository layout: reon/setup-script/<this>, mobile-relay beside reon.
    REON_SRC="$(cd "$SCRIPT_DIR/.." && pwd)"
    RELAY_SRC="$(cd "$REON_SRC/.." && pwd)/mobile-relay"
fi
# Generated nginx/php-fpm/systemd files live next to the script (ignored by
# git in the repository layout).
DEPLOY_DIR="$SCRIPT_DIR/deploy"

OPT_REON=/opt/reon
OPT_RELAY=/opt/mobile-relay

SYS_USER=reon
SYS_GROUP=reon

# Versions used for the "download a real tarball / installer" runtimes.
# These are pinned on purpose so re-running the script is deterministic;
# bump them and re-run to upgrade (the /opt/node + /opt/dotnet symlinks make
# that a one-line change).
NODE_VERSION="${NODE_VERSION:-22.11.0}"
DOTNET_CHANNEL="${DOTNET_CHANNEL:-9.0}"

# Public-facing ports -> internal ports (matches reon/docker-compose.yml,
# which already maps 587->container:25 and dns host:5453->container:53).
SMTP_INTERNAL_PORT=25
SMTP_SUBMISSION_PORT=587
POP3_PORT=110
DNS_INTERNAL_PORT=5453
DNS_STANDARD_PORT=53
HTTP_PORT=80
HTTPS_PORT=443
RELAY_PORT=31227

SETUP_LOG="/var/log/reon-setup.log"

# ---------------------------------------------------------------------------
# Logging helpers
# ---------------------------------------------------------------------------

c_reset=$'\033[0m'; c_red=$'\033[31m'; c_green=$'\033[32m'; c_yellow=$'\033[33m'; c_blue=$'\033[34m'

log_step()  { printf '\n%s==>%s %s\n' "$c_blue"  "$c_reset" "$*"; }
log_info()  { printf '%s[info]%s %s\n'  "$c_green"  "$c_reset" "$*"; }
log_warn()  { printf '%s[warn]%s %s\n'  "$c_yellow" "$c_reset" "$*" >&2; }
log_error() { printf '%s[error]%s %s\n' "$c_red"    "$c_reset" "$*" >&2; }

trap 'log_error "Setup failed at line $LINENO. See $SETUP_LOG for the full transcript."' ERR

# ---------------------------------------------------------------------------
# Pre-flight checks
# ---------------------------------------------------------------------------

require_root() {
    if [[ "${EUID}" -ne 0 ]]; then
        log_error "This script must be run as root (e.g. sudo ./setup-reon.sh)."
        exit 1
    fi
}

require_layout() {
    if [[ ! -d "$REON_SRC/web" || ! -d "$RELAY_SRC" ]]; then
        log_error "Expected the reon checkout at '$REON_SRC' and mobile-relay at '$RELAY_SRC'."
        log_error "Either run this script from reon/setup-script/ with mobile-relay cloned next to reon/,"
        log_error "or from a folder that contains both 'reon/' and 'mobile-relay/'."
        exit 1
    fi
}

# The .NET SDK + node_modules x5 + composer vendor + mysql + apt packages can
# peak well past 4-5GB of writes. Fail fast with a clear message instead of
# burning 20 minutes into a build that dies with "No space left on device".
# A very common cause on a VM that was previously used for `docker compose`:
# leftover images/layers/build-cache under /var/lib/docker.
require_disk_space() {
    log_step "Checking free disk space"
    local avail_kb avail_gb
    avail_kb="$(df -Pk "$SCRIPT_DIR" | awk 'NR==2{print $4}')"
    avail_gb=$(( avail_kb / 1024 / 1024 ))
    log_info "Free space on $(df -Pk "$SCRIPT_DIR" | awk 'NR==2{print $6}'): ${avail_gb}GB"

    if (( avail_kb < 6 * 1024 * 1024 )); then
        log_error "Less than 6GB free — the .NET SDK build alone can need 2-3GB, plus"
        log_error "node_modules, composer vendor, MySQL and apt packages on top of that."
        if command -v docker >/dev/null 2>&1; then
            log_error "Docker is installed here — if you tested docker-compose on this VM"
            log_error "before, leftover images/layers are the most likely culprit:"
            log_error "  sudo du -sh /var/lib/docker"
            log_error "  sudo docker system prune -a --volumes -f"
        fi
        log_error "Also worth checking: sudo du -xh --max-depth=1 / 2>/dev/null | sort -rh | head -20"
        exit 1
    fi
}

# On a 1GB VM /tmp is sometimes a small tmpfs, which can fail long before the
# real disk (backing $SCRIPT_DIR) would — used for our own large downloads
# (Node/.NET/Composer). Deliberately NOT exported: doing that used to leak
# into every child process, including `apt-get`/dpkg, which made MySQL's
# postinst try to write its own temp files here (not writable by the mysql
# user) and fail. Passed explicitly (mktemp -p, or a one-off TMPDIR=... on a
# single command) only where actually needed instead.
setup_tmpdir() {
    DL_TMPDIR="$SCRIPT_DIR/.setup-tmp"
    mkdir -p "$DL_TMPDIR"
    trap 'rm -rf "$DL_TMPDIR"' EXIT
}

# Fails loudly (as requested) when a file the user was supposed to prepare is
# missing, instead of silently generating one for them.
require_prepared_file() {
    local path="$1" example="$2" what="$3"
    if [[ ! -f "$path" ]]; then
        log_error "Missing required file: $path"
        log_error "  ($what)"
        if [[ -n "$example" ]]; then
            log_error "  Create it first, e.g.: cp \"$example\" \"$path\"  (then edit it)"
        else
            log_error "  Create it first (see mobile-relay/create_db.sql for the [mysql] fields it needs)."
        fi
        MISSING_FILES=1
    fi
}

check_required_files() {
    log_step "Checking required config files"
    MISSING_FILES=0
    require_prepared_file "$REON_SRC/.env"          "$REON_SRC/example.env"          "reon/.env — MySQL credentials + EXTERNAL_IP"
    require_prepared_file "$REON_SRC/config.json"    "$REON_SRC/config.example.json"  "reon/config.json — hostname, mail, mysql settings"
    require_prepared_file "$RELAY_SRC/config.ini"    ""                                "mobile-relay/config.ini — mysql connection for the relay's own DB"
    if [[ "$MISSING_FILES" -ne 0 ]]; then
        log_error "One or more required files are missing. Create them and re-run this script."
        exit 1
    fi
    log_info "Found reon/.env, reon/config.json and mobile-relay/config.ini."
}

# ---------------------------------------------------------------------------
# .env / config.json loading (spaces + special chars in paths/passwords safe)
# ---------------------------------------------------------------------------

load_env_file() {
    local file="$1"
    while IFS='=' read -r key val; do
        [[ -z "$key" || "$key" == \#* ]] && continue
        export "$key=$val"
    done < <(grep -v '^[[:space:]]*#' "$file" | grep -v '^[[:space:]]*$')
}

json_get() {
    # json_get <file> <dotted.key> [default]
    python3 - "$1" "$2" "${3:-}" <<'PYEOF'
import json, sys
path, key, default = sys.argv[1], sys.argv[2], sys.argv[3]
with open(path) as f:
    data = json.load(f)
cur = data
for part in key.split("."):
    if isinstance(cur, dict) and part in cur:
        cur = cur[part]
    else:
        cur = default
        break
print(cur if cur is not None else default)
PYEOF
}

json_set() {
    # json_set <file> <key> <value>
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

load_config() {
    log_step "Loading reon/.env and reon/config.json"
    load_env_file "$REON_SRC/.env"
    : "${MYSQL_USER:?MYSQL_USER missing from .env}"
    : "${MYSQL_PASSWORD:?MYSQL_PASSWORD missing from .env}"
    : "${MYSQL_DATABASE:?MYSQL_DATABASE missing from .env}"
    : "${EXTERNAL_IP:?EXTERNAL_IP missing from .env}"

    CFG_HOSTNAME="$(json_get "$REON_SRC/config.json" hostname)"
    CFG_MYSQL_HOST="$(json_get "$REON_SRC/config.json" mysql_host)"

    if [[ "$CFG_HOSTNAME" == "example.net" ]]; then
        log_warn "config.json 'hostname' is still the placeholder 'example.net'."
        log_warn "The site will work, but real devices/DNS need your real domain there."
    fi

    # "db" is the docker-compose service name; meaningless outside docker.
    # This is the one field this script will auto-correct, since it's a pure
    # infra detail (not app behavior) and README explicitly says not to touch
    # this value "unless you plan to use an external MySQL database" — which,
    # natively, MySQL running on this same host effectively is.
    if [[ "$CFG_MYSQL_HOST" == "db" ]]; then
        log_warn "config.json mysql_host is 'db' (the docker service name)."
        log_warn "Rewriting it to '127.0.0.1' for the native install (backup saved as config.json.bak)."
        cp -n "$REON_SRC/config.json" "$REON_SRC/config.json.bak"
        json_set "$REON_SRC/config.json" mysql_host "127.0.0.1"
    fi

    log_info "MySQL database: $MYSQL_DATABASE (user: $MYSQL_USER)"
    log_info "External IP for DNS answers: $EXTERNAL_IP"
}

# ---------------------------------------------------------------------------
# Base system setup
# ---------------------------------------------------------------------------

apt_install() {
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "$@"
}

setup_base_packages() {
    log_step "Installing base packages"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -y

    local pkgs=(
        curl wget ca-certificates gnupg lsb-release unzip git jq
        build-essential pkg-config
        default-libmysqlclient-dev
        python3 python3-venv python3-dev
        nginx mysql-server
        php-fpm php-mysql php-gd php-mbstring php-xml php-curl php-intl php-zip php-cli
        dnsmasq
        iptables iptables-persistent netfilter-persistent
    )
    # dnsmasq's postinst tries to start it on :53, which usually conflicts
    # with systemd-resolved and can make its unit report "failed" — that's
    # fine (we reconfigure it onto ${DNS_INTERNAL_PORT} in setup_dns below),
    # but don't let that transient failure abort the whole apt transaction.
    apt_install "${pkgs[@]}" || log_warn "apt-get install reported an error (likely dnsmasq's initial :53 bind); verifying packages individually."

    local missing=()
    for p in "${pkgs[@]}"; do
        dpkg -s "$p" >/dev/null 2>&1 || missing+=("$p")
    done
    if [[ "${#missing[@]}" -gt 0 ]]; then
        log_error "These required packages failed to install: ${missing[*]}"
        exit 1
    fi

    apt-get clean
}

setup_swap() {
    log_step "Checking swap space"
    if swapon --show=NAME --noheadings | grep -q .; then
        log_info "Swap already active, skipping."
        return
    fi
    local mem_kb
    mem_kb="$(awk '/MemTotal/{print $2}' /proc/meminfo)"
    if (( mem_kb > 3000000 )); then
        log_info "Plenty of RAM ($(( mem_kb / 1024 )) MiB), skipping swapfile."
        return
    fi
    log_info "Low-memory host detected ($(( mem_kb / 1024 )) MiB RAM). Creating a 2G swapfile."
    fallocate -l 2G /swapfile || dd if=/dev/zero of=/swapfile bs=1M count=2048
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
    sysctl -w vm.swappiness=10 >/dev/null
    grep -q '^vm.swappiness' /etc/sysctl.conf || echo 'vm.swappiness=10' >> /etc/sysctl.conf
}

create_system_user() {
    log_step "Creating system user '$SYS_USER'"
    if ! id -u "$SYS_USER" >/dev/null 2>&1; then
        useradd --system --create-home --home-dir /var/lib/reon --shell /usr/sbin/nologin "$SYS_USER"
        log_info "Created user $SYS_USER."
    else
        log_info "User $SYS_USER already exists."
    fi
}

link_sources() {
    log_step "Linking source checkouts into /opt"
    ln -sfn "$REON_SRC" "$OPT_REON"
    ln -sfn "$RELAY_SRC" "$OPT_RELAY"
    mkdir -p "$DEPLOY_DIR"/{nginx,php-fpm,systemd,scripts}
    log_info "$OPT_REON -> $REON_SRC"
    log_info "$OPT_RELAY -> $RELAY_SRC"

    fix_traversal_perms
}

# nginx (www-data), php-fpm/mail/mobile-relay/cron (reon) all need to chdir
# or stat files through the /opt symlinks above and down into the checkout.
# If the checkout lives under a user's $HOME, Ubuntu's default 750 on that
# home directory blocks traversal for every other account — even though the
# symlink itself resolves fine, systemd fails with "CHDIR: Permission
# denied" and nginx just reports the file as missing (404). Grant execute
# ("pass-through", not read/listing) on every ancestor dir up to root, plus
# every directory inside the checkout, without loosening file permissions.
fix_traversal_perms() {
    local dir="$SCRIPT_DIR"
    while [[ "$dir" != "/" ]]; do
        chmod o+x "$dir" 2>/dev/null || true
        dir="$(dirname "$dir")"
    done
    find "$REON_SRC" "$RELAY_SRC" -type d -exec chmod o+x {} +
}

# ---------------------------------------------------------------------------
# MySQL
# ---------------------------------------------------------------------------

setup_mysql() {
    log_step "Configuring MySQL"
    systemctl enable --now mysql

    # Written directly (not symlinked from $DEPLOY_DIR): mysqld's AppArmor
    # profile only allows reading config under paths like /etc/mysql/**, so
    # a symlink resolving into $HOME gets silently denied (see
    # `apparmor="DENIED" ... profile="/usr/sbin/mysqld"` in `journalctl -k`)
    # even though the symlink itself sits in an allowed directory — AppArmor
    # mediates on the resolved target path. Fully regenerated every run
    # anyway, so nothing is lost by not symlinking it.
    cat > /etc/mysql/mysql.conf.d/zz-reon-tuning.cnf <<EOF
# Generated by setup-reon.sh — tuned for a low-memory VM.
# The dockerized mysql:8.4.0 image was observed using ~500MB RSS; these
# limits keep native mysqld well under that on a 1GB host.
[mysqld]
innodb_buffer_pool_size = 128M
innodb_log_buffer_size = 16M
key_buffer_size = 16M
max_connections = 30
table_open_cache = 400
thread_cache_size = 8
performance_schema = OFF
# Migrations create triggers (e.g. battle tower rate-limiting); MySQL 8
# refuses that without SUPER unless this is set, since binary logging is on.
log_bin_trust_function_creators = 1
EOF
    systemctl restart mysql

    log_info "Ensuring database/user for reon ($MYSQL_DATABASE / $MYSQL_USER)"
    mysql --protocol=socket -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS '${MYSQL_USER}'@'localhost' IDENTIFIED BY '${MYSQL_PASSWORD}';
CREATE USER IF NOT EXISTS '${MYSQL_USER}'@'127.0.0.1' IDENTIFIED BY '${MYSQL_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}\`.* TO '${MYSQL_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}\`.* TO '${MYSQL_USER}'@'127.0.0.1';
SQL

    # mobile-relay owns a separate, self-managed database (it CREATE TABLE
    # IF NOT EXISTS's its own schema on startup) — read its name from its own
    # config.ini instead of assuming it matches reon's database.
    local relay_db relay_user relay_pass
    relay_db="$(python3 -c "import configparser;c=configparser.ConfigParser();c.read('$RELAY_SRC/config.ini');print(c['mysql'].get('db','mobile'))" 2>/dev/null || echo mobile)"
    relay_user="$(python3 -c "import configparser;c=configparser.ConfigParser();c.read('$RELAY_SRC/config.ini');print(c['mysql'].get('user',''))" 2>/dev/null || true)"
    relay_pass="$(python3 -c "import configparser;c=configparser.ConfigParser();c.read('$RELAY_SRC/config.ini');print(c['mysql'].get('passwd',''))" 2>/dev/null || true)"

    if [[ -n "$relay_user" ]]; then
        log_info "Ensuring database/user for mobile-relay ($relay_db / $relay_user)"
        mysql --protocol=socket -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${relay_db}\`;
CREATE USER IF NOT EXISTS '${relay_user}'@'localhost' IDENTIFIED BY '${relay_pass}';
GRANT ALL PRIVILEGES ON \`${relay_db}\`.* TO '${relay_user}'@'localhost';
SQL
    else
        log_warn "Could not read [mysql] user from mobile-relay/config.ini; skipping its DB provisioning."
    fi

    mysql --protocol=socket -uroot -e "FLUSH PRIVILEGES;"
}

# ---------------------------------------------------------------------------
# PHP-FPM + Composer + web
# ---------------------------------------------------------------------------

detect_php_fpm() {
    PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    PHP_FPM_SERVICE="php${PHP_VER}-fpm"
    PHP_FPM_SOCK="/run/php/reon.sock"
}

install_composer() {
    if command -v composer >/dev/null 2>&1; then
        return
    fi
    log_info "Installing Composer"
    local tmp
    tmp="$(mktemp -p "$DL_TMPDIR")"
    curl -sS https://getcomposer.org/installer -o "$tmp"
    php "$tmp" --install-dir=/usr/local/bin --filename=composer
    rm -f "$tmp"
}

setup_php_web() {
    log_step "Setting up PHP-FPM + web app"
    detect_php_fpm
    install_composer

    mkdir -p "$OPT_REON/web/tmp"
    chown -R "$SYS_USER:$SYS_GROUP" "$OPT_REON/web/tmp"
    mkdir -p /tmp/reon
    chown "$SYS_USER:$SYS_GROUP" /tmp/reon
    # The pool writes PHP errors here (see php_admin_value[error_log] below).
    # It has to exist and belong to the pool user before FPM starts, or the
    # log silently goes nowhere -- which is the exact failure this directory
    # was added to prevent.
    mkdir -p /var/log/reon
    chown "$SYS_USER:$SYS_GROUP" /var/log/reon
    chmod 1770 /tmp/reon

    log_info "Running composer install (this can take a while on a small VM)"
    # No --no-dev: phinx (the migration runner used below) lives in
    # require-dev, and Docker's own build includes dev deps too.
    (cd "$OPT_REON/web" && COMPOSER_ALLOW_SUPERUSER=1 composer install --optimize-autoloader)

    build_legality_checker

    cat > "$DEPLOY_DIR/php-fpm/reon-pool.conf" <<EOF
; Generated by setup-reon.sh
[reon]
user = ${SYS_USER}
group = ${SYS_GROUP}
listen = ${PHP_FPM_SOCK}
listen.owner = ${SYS_USER}
listen.group = www-data
listen.mode = 0660

pm = ondemand
; A Game Boy session is several sequential requests held open over a slow
; link, so workers are occupied far longer than a normal page render. At 6
; the pool hit its ceiling repeatedly under nothing heavier than one person
; testing; each worker measures about 4 MB resident, so 12 costs little.
pm.max_children = 12
pm.process_idle_timeout = 30s

chdir = ${OPT_REON}/web/htdocs

; PHP errors and error_log() calls from the app. Without an explicit file the
; pool discards worker output entirely: log_errors is On by default, but the
; messages have nowhere to go, so every error_log() in the codebase is a no-op
; and failures that only report themselves that way vanish.
php_admin_flag[log_errors] = On
php_admin_value[error_log] = /var/log/reon/php-error.log

env[POKEMON_LEGALITY_BIN] = ${LEGALITY_BIN}
php_admin_value[sys_temp_dir] = /tmp/reon
php_admin_value[upload_tmp_dir] = /tmp/reon
php_admin_value[session.save_path] = /tmp/reon
EOF
    ln -sfn "$DEPLOY_DIR/php-fpm/reon-pool.conf" "/etc/php/${PHP_VER}/fpm/pool.d/reon.conf"

    systemctl enable --now "$PHP_FPM_SERVICE"
    systemctl restart "$PHP_FPM_SERVICE"
}

build_legality_checker() {
    log_step "Building the Pokémon legality checker (.NET)"
    install_dotnet

    local proj="$OPT_REON/app/pokemon-legality/LegalityCheckerConsole/LegalityCheckerConsole.csproj"
    local out="$OPT_REON/app/pokemon-legality/LegalityCheckerConsole/publish"
    local arch rid
    arch="$(uname -m)"
    case "$arch" in
        x86_64) rid=linux-x64 ;;
        aarch64|arm64) rid=linux-arm64 ;;
        *) rid="" ;;
    esac

    mkdir -p "$out"
    (
        cd "$OPT_REON/app/pokemon-legality/LegalityCheckerConsole"
        export PATH="/opt/dotnet:$PATH"
        dotnet restore "$proj"
        if [[ -n "$rid" ]] && dotnet build "$proj" --no-restore -c Release --framework "net${DOTNET_CHANNEL}" -r "$rid" --self-contained -o "$out"; then
            :
        else
            log_warn "Self-contained build for RID '$rid' failed/unsupported; falling back to a framework-dependent build."
            dotnet build "$proj" -c Release --framework "net${DOTNET_CHANNEL}" -o "$out"
        fi
    )
    LEGALITY_BIN="$out/LegalityCheckerConsole"
    if [[ ! -f "$LEGALITY_BIN" ]]; then
        log_warn "Legality checker binary not found at $LEGALITY_BIN after build; pokemon legality checks may fail."
    fi
}

install_dotnet() {
    # Check the SDK actually works, not just that the top-level binary
    # exists: a tar extraction that ran out of space mid-way can leave
    # `dotnet` executable while later files (e.g. sdk/*/dotnet.deps.json)
    # are empty/truncated, which passed a plain -x check but breaks on use.
    if [[ -x /opt/dotnet/dotnet ]] && /opt/dotnet/dotnet --version >/dev/null 2>&1; then
        return
    fi
    # Clear out any half-extracted/corrupted SDK before retrying.
    rm -rf /opt/dotnet
    log_info "Installing .NET SDK ${DOTNET_CHANNEL} via dotnet-install.sh"
    local tmp
    tmp="$(mktemp -p "$DL_TMPDIR")"
    curl -sSL https://dot.net/v1/dotnet-install.sh -o "$tmp"
    chmod +x "$tmp"
    # TMPDIR set only for this one command (not exported) — dotnet-install.sh
    # downloads the full SDK tarball via its own internal temp file there.
    TMPDIR="$DL_TMPDIR" "$tmp" --channel "$DOTNET_CHANNEL" --install-dir /opt/dotnet
    rm -f "$tmp"
    ln -sfn /opt/dotnet/dotnet /usr/local/bin/dotnet
}

run_migrations() {
    log_step "Running database migrations (phinx)"
    (cd "$OPT_REON" && php web/vendor/bin/phinx migrate)
}

# ---------------------------------------------------------------------------
# nginx
# ---------------------------------------------------------------------------

# Shared location blocks for the reon web app — used verbatim in both the
# plain-HTTP config and the HTTPS server block, so there's exactly one place
# that knows how to route PHP/Battle Tower requests.
_nginx_app_locations() {
    cat <<EOF
    gzip on;
    access_log /var/log/nginx/reon.access.log;
    error_log /var/log/nginx/reon.error.log;

    root ${OPT_REON}/web/htdocs;
    index index.php index.html index.htm;

    # Battle Tower dynamic routing (see reon/examples/nginx/default.conf.template
    # for why this exists: nginx doesn't read the Apache .htaccess rewrite).
    location = /cgb/download {
        if (\$arg_name ~ "^/01/CGB-BXT([A-Z])/battle/room(....)\.cgb\$") {
            set \$args "name=/01/CGB-BXT\$1/battle/room.cgb&room=\$2";
        }
        if (\$arg_name ~ "^/01/CGB-BXT([A-Z])/battle/leaders(....)\.cgb\$") {
            set \$args "name=/01/CGB-BXT\$1/battle/leaders.cgb&room=\$2";
        }
        rewrite ^ /cgb/download.php last;
    }

    location /cgb/ {
        try_files \$uri \$uri/ @extensionless-php;
    }

    # Device-auth endpoint (see DeviceAuthUtil.php) -- called directly by
    # libmobile-derived frontends over device.auth.dion.ne.jp, extensionless
    # per the agreed wire contract.
    location /api/ {
        try_files \$uri \$uri/ @extensionless-php;
    }

    location ~ \.php\$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_index index.php;
        fastcgi_pass unix:${PHP_FPM_SOCK};
    }

    location / {
        try_files \$uri \$uri/ =404;
    }

    location @extensionless-php {
        rewrite ^(.*)\$ \$1.php last;
    }
EOF
}

# Regenerated on every run. If a Let's Encrypt cert already exists for
# ${CFG_HOSTNAME} (obtained by setup_tls, called right after this in main),
# switch to serving over HTTPS with an HTTP->HTTPS redirect; otherwise plain
# HTTP. This makes nginx config idempotent with respect to TLS state instead
# of relying on certbot's --nginx plugin to mutate a file we regenerate.
setup_nginx() {
    log_step "Configuring nginx"
    local cert_dir="/etc/letsencrypt/live/${CFG_HOSTNAME}"
    local app_locations
    app_locations="$(_nginx_app_locations)"

    if [[ -f "$cert_dir/fullchain.pem" && -f "$cert_dir/privkey.pem" ]]; then
        log_info "Serving ${CFG_HOSTNAME} over HTTP and HTTPS (certificate found)."
        # The HTTP->HTTPS redirect is narrow on purpose. The game-facing
        # endpoints (/01/..., /cgb/..., /api/...) are hit by real Mobile
        # Adapter GB hardware and emulators through libmobile, using a bare
        # HTTP/1.0-era client from ~2001 that does not follow redirects and
        # has no TLS support at all — a blanket 301 breaks every in-game
        # network feature. So only browser traffic on the human hostname is
        # sent to HTTPS; see the gates in the generated block below.
        cat > "$DEPLOY_DIR/nginx/reon.conf" <<EOF
# Generated by setup-reon.sh — TLS active for ${CFG_HOSTNAME}
server {
    listen ${HTTP_PORT};
    server_name ${CFG_HOSTNAME} _;

    # ---- HTTP -> HTTPS, but only for the human hostname ----
    #
    # Port 80 is shared: the Game Boy reaches its own hostnames here and
    # cannot speak TLS at all, and the certificate covers ${CFG_HOSTNAME}
    # alone. So the redirect is gated three ways, and each gate has a reason:
    #
    #   \$http_host, not \$host -- \$host falls back to the first server_name
    #     when the request carries no Host header, which HTTP/1.0 clients
    #     like the adapter often do. That would redirect exactly the traffic
    #     that must not be redirected. \$http_host is empty in that case.
    #   machine paths exempt -- /cgb/, /api/ and the /NN/ game content are
    #     fetched by the adapter, never by a browser. Redirecting them can
    #     only break things.
    #   /.well-known/ exempt -- certbot renews by webroot over HTTP. A
    #     blanket redirect here would break renewal, silently, in 90 days.
    set \$reon_to_https 0;
    if (\$http_host ~* "^${CFG_HOSTNAME//./\\.}(:${HTTP_PORT})?\$") { set \$reon_to_https 1; }
    if (\$uri ~ "^/(cgb|api|[0-9]{2})/")                { set \$reon_to_https 0; }
    if (\$uri ~ "^/\\.well-known/")                     { set \$reon_to_https 0; }
    if (\$reon_to_https = 1) { return 301 https://${CFG_HOSTNAME}\$request_uri; }

$app_locations
}

server {
    listen ${HTTPS_PORT} ssl;
    http2 on;
    server_name ${CFG_HOSTNAME};

    ssl_certificate     ${cert_dir}/fullchain.pem;
    ssl_certificate_key ${cert_dir}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;

$app_locations
}
EOF
    else
        cat > "$DEPLOY_DIR/nginx/reon.conf" <<EOF
# Generated by setup-reon.sh from reon/examples/nginx/default.conf.template
server {
    listen ${HTTP_PORT};
    server_name ${CFG_HOSTNAME} _;

$app_locations
}
EOF
    fi

    rm -f /etc/nginx/sites-enabled/default
    ln -sfn "$DEPLOY_DIR/nginx/reon.conf" /etc/nginx/sites-enabled/reon.conf
    nginx -t
    systemctl enable --now nginx
    systemctl reload nginx
}

# ---------------------------------------------------------------------------
# HTTPS (Let's Encrypt via certbot, webroot method — doesn't touch nginx
# config directly, so it can't fight with setup_nginx() regenerating it above)
# ---------------------------------------------------------------------------

setup_tls() {
    log_step "Checking HTTPS (Let's Encrypt)"

    if [[ "$CFG_HOSTNAME" == "example.net" || "$CFG_HOSTNAME" != *.* ]]; then
        log_warn "Skipping HTTPS: config.json 'hostname' isn't a real domain yet."
        return
    fi

    if ! apt_install certbot; then
        log_warn "Could not install certbot; skipping HTTPS (site keeps serving over plain HTTP)."
        return
    fi

    local cert_dir="/etc/letsencrypt/live/${CFG_HOSTNAME}"
    if [[ -f "$cert_dir/fullchain.pem" ]]; then
        log_info "Certificate already present for ${CFG_HOSTNAME} (renews automatically via certbot's own timer)."
        return
    fi

    log_info "Requesting a Let's Encrypt certificate for ${CFG_HOSTNAME}."
    log_info "This needs ${CFG_HOSTNAME}'s DNS A record already pointing at this VM's public IP."

    local email_args=(--register-unsafely-without-email)
    if [[ -n "${LETSENCRYPT_EMAIL:-}" ]]; then
        email_args=(--email "$LETSENCRYPT_EMAIL")
    fi

    if certbot certonly --webroot -w "$OPT_REON/web/htdocs" -d "$CFG_HOSTNAME" \
        --non-interactive --agree-tos "${email_args[@]}" \
        --deploy-hook "systemctl reload nginx"; then
        log_info "Certificate obtained for ${CFG_HOSTNAME}."
    else
        log_warn "Could not obtain a certificate — DNS for ${CFG_HOSTNAME} may not have propagated to this VM's IP yet."
        log_warn "Site will keep serving over plain HTTP for now; re-run this script once DNS resolves."
    fi
}

# ---------------------------------------------------------------------------
# dnsmasq (fake DNS pointing *.dion.ne.jp at EXTERNAL_IP)
# ---------------------------------------------------------------------------

setup_dns() {
    log_step "Configuring dnsmasq (internal port ${DNS_INTERNAL_PORT})"

    # Some virtualized kernels silently fail to deliver UDP packets to a
    # socket bound to the 0.0.0.0 wildcard (dnsmasq needs IP_PKTINFO
    # ancillary data on wildcard binds to know which local address a packet
    # arrived on; on this class of host that mechanism just doesn't work —
    # the packet reaches the interface and passes the firewall, dnsmasq's
    # socket is bound, but it never logs or answers). Confirmed by binding
    # to a single concrete address instead, which works immediately. So:
    # bind explicitly to loopback (for local tooling) plus this host's real
    # outbound-facing IP (where Oracle's NAT actually delivers traffic sent
    # to the public IP) — detected at runtime, never hardcoded.
    local primary_ip
    primary_ip="$(ip route get 1.1.1.1 2>/dev/null | grep -oP 'src \K\S+' | head -1)"
    if [[ -z "$primary_ip" ]]; then
        log_warn "Could not detect this host's primary IP; falling back to 0.0.0.0 (known to fail on some kernels — see comment in setup_dns())."
        primary_ip="0.0.0.0"
    fi

    # Written directly (not symlinked from $DEPLOY_DIR like the other
    # generated configs) for simplicity — it's fully regenerated every run
    # anyway, so there's nothing lost: the update-via-git-pull benefit of
    # symlinking only applies to the actual app source, not to a config file
    # this script always overwrites from scratch.
    cat > /etc/dnsmasq.d/reon.conf <<EOF
# Generated by setup-reon.sh from reon/docker-dns-entry.sh
port=${DNS_INTERNAL_PORT}
no-resolv
no-hosts
# dnsmasq >=2.79 refuses queries from outside directly-connected subnets by
# default (anti open-resolver protection) — this server needs to answer
# players anywhere on the internet. Ubuntu's packaging unconditionally adds
# --local-service on the command line (see /usr/share/dnsmasq/init-system-
# common), so "no-local-service" here would directly contradict it and make
# dnsmasq's own config-check (systemd's ExecStartPre) fail. An explicit
# listen-address instead makes dnsmasq ignore --local-service entirely
# (documented behavior), without that conflict — and also sidesteps the
# 0.0.0.0/IP_PKTINFO issue described above.
listen-address=127.0.0.1
listen-address=${primary_ip}
server=1.1.1.3
server=1.0.0.3
address=/*.dion.ne.jp/${EXTERNAL_IP}
address=/gameboy.datacenter.ne.jp/${EXTERNAL_IP}
log-queries
EOF
    # Clear out any failed state left over from the package's initial start
    # attempt on :53 (see setup_base_packages) before retrying on our port.
    systemctl reset-failed dnsmasq 2>/dev/null || true
    systemctl enable dnsmasq
    systemctl restart dnsmasq
}

# ---------------------------------------------------------------------------
# Node.js runtime (tarball install, distro-independent)
# ---------------------------------------------------------------------------

install_node() {
    if [[ -x /opt/node/bin/node ]] && /opt/node/bin/node --version | grep -q "v${NODE_VERSION}"; then
        return
    fi
    log_step "Installing Node.js ${NODE_VERSION}"
    local arch node_arch
    arch="$(uname -m)"
    case "$arch" in
        x86_64) node_arch=x64 ;;
        aarch64|arm64) node_arch=arm64 ;;
        *) log_error "Unsupported architecture for Node.js: $arch"; exit 1 ;;
    esac
    local dist="node-v${NODE_VERSION}-linux-${node_arch}"
    local tmp
    tmp="$(mktemp -d -p "$DL_TMPDIR")"
    curl -sSL "https://nodejs.org/dist/v${NODE_VERSION}/${dist}.tar.xz" -o "$tmp/node.tar.xz"
    tar -xJf "$tmp/node.tar.xz" -C /opt
    ln -sfn "/opt/${dist}" /opt/node
    ln -sfn /opt/node/bin/node /usr/local/bin/node
    ln -sfn /opt/node/bin/npm /usr/local/bin/npm
    ln -sfn /opt/node/bin/npx /usr/local/bin/npx
    rm -rf "$tmp"
    log_info "Node $(node --version) installed at /opt/node"
}

npm_ci_dir() {
    local dir="$1"
    if [[ -f "$dir/package-lock.json" ]]; then
        (cd "$dir" && npm ci --omit=dev)
    else
        (cd "$dir" && npm install --omit=dev)
    fi
    # `npm ci` installs exactly what's pinned in package-lock.json, which can
    # be a stale, vulnerable resolution even though package.json's own semver
    # range (e.g. ^3.5.2) already permits a patched version — this applies
    # any fix available within that declared range (never a major bump, so
    # it won't change the app's API surface). A remaining "needs --force"
    # finding means the fix requires a breaking major version and is left
    # for a deliberate, tested upgrade rather than being silently applied.
    (cd "$dir" && npm audit fix --omit=dev) || log_warn "$dir: some vulnerabilities need a breaking upgrade (npm audit fix --force) — left as-is, review manually."
}

setup_node_apps() {
    log_step "Installing Node dependencies for mail + cron apps"
    install_node
    npm_ci_dir "$OPT_REON/mail"
    npm_ci_dir "$OPT_REON/app/pokemon-battle"
    npm_ci_dir "$OPT_REON/app/pokemon-exchange"
    npm_ci_dir "$OPT_REON/app/auto-schedule"
    npm_ci_dir "$OPT_REON/app/mail-bottle"
    fix_auto_schedule_perms
}

# auto-schedule is the only cron job that writes to disk -- the other three
# (pokemon-battle, pokemon-exchange, mail-bottle) only touch the database.
# It runs as $SYS_USER but writes into the checkout, which is owned by
# whoever deployed it, so without this it dies on EACCES and the news
# rotation silently never happens.
#
# Ownership is left with the deploying user (so redeploys keep working) and
# only the group is handed over. setgid on the directories is the part that
# makes it stick: without it every file copied in by a later deploy comes
# back owned by the deployer's group and the service loses write access to
# that file again.
fix_auto_schedule_perms() {
    local paths=(
        "$OPT_REON/web/cgb/pokemon"        # bxt_config.php, bxt_runtime_state.json
        "$OPT_REON/app/auto-schedule/files" # cycle_state.json, rotation targets
    )
    for p in "${paths[@]}"; do
        [[ -d "$p" ]] || continue
        chgrp -R "$SYS_GROUP" "$p"
        chmod -R g+w "$p"
        find "$p" -type d -exec chmod g+s {} +
    done
    log_info "auto-schedule write paths granted to $SYS_GROUP (setgid)"
}

# ---------------------------------------------------------------------------
# mobile-relay (Python)
# ---------------------------------------------------------------------------

setup_mobile_relay() {
    log_step "Setting up mobile-relay (Python)"
    if [[ ! -d "$OPT_RELAY/.venv" ]]; then
        python3 -m venv "$OPT_RELAY/.venv"
    fi
    "$OPT_RELAY/.venv/bin/pip" install --upgrade pip >/dev/null
    "$OPT_RELAY/.venv/bin/pip" install mysqlclient
    chown -R "$SYS_USER:$SYS_GROUP" "$OPT_RELAY/.venv"
}

# ---------------------------------------------------------------------------
# systemd units (mail, mobile-relay, cron timers)
# ---------------------------------------------------------------------------

write_unit() {
    local name="$1" content="$2"
    printf '%s' "$content" > "$DEPLOY_DIR/systemd/$name"
    ln -sfn "$DEPLOY_DIR/systemd/$name" "/etc/systemd/system/$name"
}

setup_systemd_units() {
    log_step "Writing systemd units"

    # --- Mail (SMTP :25 + POP3 :110, hardcoded in mail/smtp.js & pop3.js) ---
    write_unit reon-mail.service "$(cat <<EOF
[Unit]
Description=REON Mail Service (SMTP/POP3)
Documentation=https://github.com/REONTeam/reon
After=network.target mysql.service
Requires=mysql.service

[Service]
Type=simple
User=${SYS_USER}
Group=${SYS_GROUP}
WorkingDirectory=${OPT_REON}/mail
ExecStart=/opt/node/bin/node ${OPT_REON}/mail/index.js -c ${OPT_REON}/config.json
AmbientCapabilities=CAP_NET_BIND_SERVICE
Restart=on-failure
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
)"

    # --- mobile-relay (:31227, unprivileged) ---
    write_unit reon-mobile-relay.service "$(cat <<EOF
[Unit]
Description=REON Mobile Adapter Relay
Documentation=https://github.com/REONTeam/reon
After=network.target mysql.service
Requires=mysql.service

[Service]
Type=simple
User=${SYS_USER}
Group=${SYS_GROUP}
WorkingDirectory=${OPT_RELAY}
ExecStart=${OPT_RELAY}/.venv/bin/python3 ${OPT_RELAY}/server.py
Environment=PYTHONUNBUFFERED=1
Restart=on-failure
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
)"

    # --- Cron-equivalent jobs (docker.crontab), as oneshot services + timers ---
    write_cron_unit() {
        local slug="$1" desc="$2" script_rel="$3" calendar="$4"
        write_unit "reon-${slug}.service" "$(cat <<EOF
[Unit]
Description=REON cron job: ${desc}
After=network.target mysql.service

[Service]
Type=oneshot
User=${SYS_USER}
Group=${SYS_GROUP}
WorkingDirectory=${OPT_REON}/app/${slug}
ExecStart=/opt/node/bin/node ${OPT_REON}/app/${script_rel} -c ${OPT_REON}/config.json
EOF
)"
        write_unit "reon-${slug}.timer" "$(cat <<EOF
[Unit]
Description=Timer for reon-${slug}.service

[Timer]
OnCalendar=${calendar}
Persistent=true

[Install]
WantedBy=timers.target
EOF
)"
    }

    write_cron_unit pokemon-battle   "Battle Tower content update" "pokemon-battle/index.js"   "*-*-* 00:00:00"
    write_cron_unit pokemon-exchange "Trade Corner trades"         "pokemon-exchange/index.js" "*-*-* *:0/15:00"
    write_cron_unit auto-schedule    "Pokémon news scheduling"     "auto-schedule/index.js"    "*-*-* *:0/15:00"
    write_cron_unit mail-bottle      "Mail de Cute trades"         "mail-bottle/index.js"      "*-*-* *:0/15:00"

    systemctl daemon-reload
}

enable_all_services() {
    log_step "Enabling and starting services"
    systemctl enable --now reon-mail.service reon-mobile-relay.service
    systemctl restart reon-mail.service reon-mobile-relay.service

    for slug in pokemon-battle pokemon-exchange auto-schedule mail-bottle; do
        systemctl enable --now "reon-${slug}.timer"
    done
}

# ---------------------------------------------------------------------------
# Firewall (iptables): 25<-587, 5453<-53, plus opening the ports we serve on.
# Oracle's default Ubuntu images ship a restrictive INPUT chain, so rules are
# inserted at the TOP (position 1) to guarantee they run before any existing
# DROP/REJECT catch-all further down.
# ---------------------------------------------------------------------------

iptables_ensure_accept() {
    local proto="$1" port="$2"
    if ! iptables -C INPUT -p "$proto" --dport "$port" -j ACCEPT 2>/dev/null; then
        iptables -I INPUT 1 -p "$proto" --dport "$port" -j ACCEPT
    fi
}

iptables_ensure_redirect() {
    local proto="$1" from_port="$2" to_port="$3"
    if ! iptables -t nat -C PREROUTING -p "$proto" --dport "$from_port" -j REDIRECT --to-port "$to_port" 2>/dev/null; then
        iptables -t nat -I PREROUTING 1 -p "$proto" --dport "$from_port" -j REDIRECT --to-port "$to_port"
    fi
}

setup_firewall() {
    log_step "Configuring iptables"

    # Never lock ourselves out of SSH.
    local ssh_port
    ssh_port="$(awk '/^Port /{print $2; exit}' /etc/ssh/sshd_config 2>/dev/null || true)"
    ssh_port="${ssh_port:-22}"
    iptables_ensure_accept tcp "$ssh_port"

    # 587 -> 25 (both external SMTP ports land on the app's hardcoded :25)
    iptables_ensure_redirect tcp "$SMTP_SUBMISSION_PORT" "$SMTP_INTERNAL_PORT"
    # 53 -> 5453 (standard DNS port redirected to dnsmasq's actual listener)
    iptables_ensure_redirect udp "$DNS_STANDARD_PORT" "$DNS_INTERNAL_PORT"

    iptables_ensure_accept tcp "$HTTP_PORT"
    iptables_ensure_accept tcp "$HTTPS_PORT"
    iptables_ensure_accept tcp "$SMTP_INTERNAL_PORT"
    iptables_ensure_accept tcp "$POP3_PORT"
    iptables_ensure_accept tcp "$RELAY_PORT"
    iptables_ensure_accept udp "$DNS_INTERNAL_PORT"

    if command -v netfilter-persistent >/dev/null 2>&1; then
        netfilter-persistent save
    fi

    if systemctl is-active --quiet ufw 2>/dev/null; then
        log_warn "ufw is active; mirroring the same rules there so it doesn't shadow iptables."
        ufw allow "${ssh_port}/tcp" || true
        ufw allow "${HTTP_PORT}/tcp" || true
        ufw allow "${HTTPS_PORT}/tcp" || true
        ufw allow "${SMTP_INTERNAL_PORT}/tcp" || true
        ufw allow "${SMTP_SUBMISSION_PORT}/tcp" || true
        ufw allow "${POP3_PORT}/tcp" || true
        ufw allow "${RELAY_PORT}/tcp" || true
        ufw allow "${DNS_STANDARD_PORT}/udp" || true
        ufw allow "${DNS_INTERNAL_PORT}/udp" || true
    fi

    log_warn "Oracle Cloud also filters traffic at the Security List / NSG level in the"
    log_warn "console — these iptables rules alone won't be reachable from the internet"
    log_warn "until you also allow ${HTTP_PORT}/tcp, ${HTTPS_PORT}/tcp, ${SMTP_SUBMISSION_PORT}/tcp, ${POP3_PORT}/tcp, ${RELAY_PORT}/tcp and ${DNS_STANDARD_PORT}/udp there."
}

# ---------------------------------------------------------------------------
# Log-monitoring helper scripts — generated here (not hand-authored), so they
# always match the actual unit/service names this run configured.
# ---------------------------------------------------------------------------

generate_log_scripts() {
    log_step "Generating log-monitoring scripts"
    local d="$DEPLOY_DIR/scripts"

    cat > "$d/reon-logs-mail.sh" <<'EOF'
#!/usr/bin/env bash
exec journalctl -u reon-mail.service -f
EOF

    cat > "$d/reon-logs-relay.sh" <<'EOF'
#!/usr/bin/env bash
exec journalctl -u reon-mobile-relay.service -f
EOF

    cat > "$d/reon-logs-web.sh" <<EOF
#!/usr/bin/env bash
exec journalctl -u nginx.service -u ${PHP_FPM_SERVICE}.service -f
EOF

    cat > "$d/reon-logs-dns.sh" <<'EOF'
#!/usr/bin/env bash
exec journalctl -u dnsmasq.service -f
EOF

    cat > "$d/reon-logs-cron.sh" <<'EOF'
#!/usr/bin/env bash
exec journalctl -u reon-pokemon-battle.service -u reon-pokemon-exchange.service \
                -u reon-auto-schedule.service -u reon-mail-bottle.service -f
EOF

    cat > "$d/reon-logs-all.sh" <<EOF
#!/usr/bin/env bash
exec journalctl -u reon-mail.service -u reon-mobile-relay.service \
                -u nginx.service -u ${PHP_FPM_SERVICE}.service -u dnsmasq.service \
                -u mysql.service \
                -u reon-pokemon-battle.service -u reon-pokemon-exchange.service \
                -u reon-auto-schedule.service -u reon-mail-bottle.service -f
EOF

    cat > "$d/reon-status.sh" <<'EOF'
#!/usr/bin/env bash
for u in reon-mail reon-mobile-relay nginx dnsmasq mysql \
         reon-pokemon-battle.timer reon-pokemon-exchange.timer \
         reon-auto-schedule.timer reon-mail-bottle.timer; do
    printf '\n== %s ==\n' "$u"
    systemctl --no-pager --lines=0 status "$u" || true
done
EOF

    cat > "$d/reon-add-user.sh" <<EOF
#!/usr/bin/env bash
cd "${OPT_REON}/web/scripts" && exec php add_user.php
EOF

    chmod +x "$d"/*.sh

    for f in "$d"/*.sh; do
        ln -sfn "$f" "/usr/local/bin/$(basename "$f" .sh)"
    done

    log_info "Log scripts ready — try: reon-logs-all  |  reon-logs-mail  |  reon-status"
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

main() {
    require_root
    require_layout
    mkdir -p "$(dirname "$SETUP_LOG")"
    exec > >(tee -a "$SETUP_LOG") 2>&1

    log_step "Target: Oracle Cloud VM.Standard.E2.1.Micro (Always Free) — 1 burstable OCPU, 1GB RAM, x86_64"
    log_info "First run can easily take 15-30+ minutes (composer/npm/dotnet builds on a throttled baseline OCPU)."
    log_info "That's expected — let it run. Progress is logged to $SETUP_LOG."

    check_required_files
    require_disk_space
    setup_tmpdir

    # Ubuntu Minimal images can lack python3 out of the box, and load_config()
    # needs it (json_get/json_set) to parse config.json — so packages first.
    setup_base_packages
    load_config

    setup_swap
    create_system_user
    link_sources

    # Open ports before setup_tls below — Let's Encrypt's HTTP-01 challenge
    # needs port 80 reachable, which requires this to have already run.
    setup_firewall

    setup_mysql
    setup_php_web
    run_migrations
    setup_nginx
    setup_tls
    setup_nginx   # regenerate once more: picks up the cert if one was issued
    setup_dns

    setup_node_apps
    setup_mobile_relay

    setup_systemd_units
    generate_log_scripts
    enable_all_services

    log_step "Done"
    local web_scheme=http
    [[ -f "/etc/letsencrypt/live/${CFG_HOSTNAME}/fullchain.pem" ]] && web_scheme=https
    log_info "Web:          ${web_scheme}://${CFG_HOSTNAME}/  (or http://$(curl -s ifconfig.me 2>/dev/null || echo "<vm-ip>")/)"
    log_info "SMTP:         port ${SMTP_INTERNAL_PORT} (also reachable via ${SMTP_SUBMISSION_PORT})"
    log_info "POP3:         port ${POP3_PORT}"
    log_info "DNS:          port ${DNS_INTERNAL_PORT} (also reachable via standard ${DNS_STANDARD_PORT})"
    log_info "mobile-relay: port ${RELAY_PORT}"
    log_info ""
    log_info "Create your first account without email delivery: reon-add-user"
    log_info "Watch logs:   reon-logs-all | reon-logs-mail | reon-logs-web | reon-logs-relay | reon-logs-dns | reon-logs-cron"
    log_info "Service status: reon-status"
    log_info ""
    log_info "Re-run this script any time after 'git pull' to pick up updates."
}

main "$@"
