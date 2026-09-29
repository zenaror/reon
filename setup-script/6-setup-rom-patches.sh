#!/usr/bin/env bash
# 6-setup-rom-patches.sh -- the routine that turns the games' source
# repositories into downloadable patches for the Downloads page.
#
# What it does, and what it does not:
#   - Builds the games from their public repositories (Pokémon Crystal in
#     five languages, Game Boy Wars 3 English) and publishes a BPS patch for
#     each, made against the OFFICIAL ROM. The patch is the only thing that
#     is ever published; the official ROMs are kept in a directory nothing
#     serves and only the build user can open.
#   - Optional, like 5-admin-control.sh: without it the Downloads page shows
#     "not published yet" and everything else is unaffected.
#
# Installs, all idempotent:
#   - the build packages (git, make, gcc/g++, bison, pkg-config, libpng-dev, Pillow)
#   - rgbds 0.6.1 and 1.0.3 built from source into /opt/reon-toolchain (the
#     Crystal forks need exactly 0.6.1 -- the distribution's newer rgbds does
#     not assemble them -- and Game Boy Wars 3 pins 1.0.3). The tarballs are
#     checked against a SHA-256 written down here.
#   - the MIPS binutils 2.42 the Stadium 2 overlays are linked with, unpacked
#     from Ubuntu's archive into /opt/reon-toolchain/mips-binutils (checked
#     against a SHA-512; it is not in this distribution's repositories)
#   - the system user `reonpatch`, which owns /var/lib/reon-patches:
#       roms/    the official ROMs (mode 0700, never served)
#       public/  the .bps files and manifest.json (nginx serves this, and
#                only this, under /patches/ -- see 1-setup-reon.sh)
#   - reon-patch-build itself, into /usr/local/lib/reon-patch-build, owned by
#     root: the web user (`reon`) can write /opt/reon, and the build user must
#     not run code the web user can edit.
#   - reon-patch-build.service/.timer (daily 05:10 UTC; a run that finds
#     nothing new takes seconds).
#
# The official ROMs are NOT part of the install. Put them away once with:
#   sudo -u reonpatch reon-patch-build add-rom /path/to/the-rom.zip ...
# (it keeps only ROMs whose SHA-1 the catalog names, and prints what is still
# missing with `reon-patch-build status`).
#
#   sudo bash 6-setup-rom-patches.sh
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "Run as root (sudo bash 6-setup-rom-patches.sh)." >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -d "$SCRIPT_DIR/reon" ]]; then
    REON_SRC="$SCRIPT_DIR/reon"
else
    REON_SRC="$(cd "$SCRIPT_DIR/.." && pwd)"
fi

TOOL_DIR=/usr/local/lib/reon-patch-build
TOOLCHAIN=/opt/reon-toolchain
STATE=/var/lib/reon-patches
BUILD_USER=reonpatch

# Source tarballs of the two rgbds releases the games are built with.
RGBDS_0_6_1_URL=https://github.com/gbdev/rgbds/releases/download/v0.6.1/rgbds-0.6.1.tar.gz
RGBDS_0_6_1_SHA256=872018509c6cbc2d9e947a1a6d509fd9dc56a010827f608c5f4fa46de2fa186a
RGBDS_1_0_3_URL=https://github.com/gbdev/rgbds/releases/download/v1.0.3/rgbds-source.tar.gz
RGBDS_1_0_3_SHA256=97b523435f7da0b6d2a58daff447bb2c8280895c3f49eb4e63e5df8da63dd64d

# The MIPS binutils the Stadium 2 build links its overlays with. Exactly this
# release: another binutils can change the compiled bytes, and the patches are
# only published when the build reproduces the maintainers' hashes. It is not
# in this distribution's repositories, so it is taken from Ubuntu's archive
# (noble) and unpacked, not installed.
MIPS_BINUTILS_URL=http://archive.ubuntu.com/ubuntu/pool/universe/b/binutils-mipsen/binutils-mips-linux-gnu_2.42-2ubuntu1cross5_amd64.deb
MIPS_BINUTILS_SHA512=b86803ee26f6ba203f8c3fcf08b9b55b90758589a0fe6492dec2aa0e3e435db3b8a8258efc716998e8410e548ac921761ec67493b0c6fbd85da40b3a7a10c8d6
# Its one library this distribution no longer ships (it has libsframe.so.3).
MIPS_SFRAME_URL=http://archive.ubuntu.com/ubuntu/pool/main/b/binutils/libsframe1_2.42-4ubuntu2.10_amd64.deb
MIPS_SFRAME_SHA512=abd51ace4188cac1a100545642e6878f84c311ba614d520f6469f59863afc98a880b57cca25204c404b9eca3e5dd4eac44418bbe1789dd45184b5e92b127bf42

c_reset=$'\033[0m'; c_red=$'\033[31m'; c_green=$'\033[32m'; c_yellow=$'\033[33m'; c_blue=$'\033[34m'
log_step()  { printf '\n%s==>%s %s\n' "$c_blue"  "$c_reset" "$*"; }
log_info()  { printf '%s[info]%s %s\n'  "$c_green"  "$c_reset" "$*"; }
log_warn()  { printf '%s[warn]%s %s\n'  "$c_yellow" "$c_reset" "$*" >&2; }
log_error() { printf '%s[error]%s %s\n' "$c_red"    "$c_reset" "$*" >&2; }

apt_install() {
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "$@"
}

install_packages() {
    log_step "Installing the build packages"
    apt-get update -qq
    apt_install git ca-certificates curl make gcc g++ bison pkg-config libpng-dev python3 python3-pil
}

# build_rgbds VERSION URL SHA256 -- skipped when that version is installed.
build_rgbds() {
    local version="$1" url="$2" sha256="$3"
    local dest="$TOOLCHAIN/rgbds-$version"
    if [[ -x "$dest/bin/rgbasm" ]] && "$dest/bin/rgbasm" --version | grep -q "v$version\$"; then
        log_info "rgbds $version already installed."
        return
    fi
    log_step "Building rgbds $version"
    local tmp
    tmp="$(mktemp -d)"
    curl -fsSL -o "$tmp/src.tar.gz" "$url"
    if ! echo "$sha256  $tmp/src.tar.gz" | sha256sum -c --quiet -; then
        log_error "rgbds $version: the download does not match the checksum recorded in this script; nothing installed."
        rm -rf "$tmp"
        exit 1
    fi
    mkdir "$tmp/src"
    tar -xzf "$tmp/src.tar.gz" -C "$tmp/src" --strip-components=1
    # One job at a time: link-time optimisation on a 1 GB machine.
    make -C "$tmp/src" -j1 >/dev/null
    make -C "$tmp/src" install PREFIX="$dest" >/dev/null
    rm -rf "$tmp"
    "$dest/bin/rgbasm" --version | grep -q "v$version\$" || { log_error "rgbds $version built, but reports another version."; exit 1; }
    log_info "rgbds $version installed in $dest."
}

install_mips_binutils() {
    local dest="$TOOLCHAIN/mips-binutils"
    export LD_LIBRARY_PATH="$dest/usr/lib/x86_64-linux-gnu"
    if [[ -x "$dest/usr/bin/mips-linux-gnu-ld.bfd" ]] && "$dest/usr/bin/mips-linux-gnu-ld.bfd" --version >/dev/null 2>&1; then
        log_info "MIPS binutils already installed."
        return
    fi
    log_step "Installing the MIPS binutils (Stadium 2 overlays)"
    local tmp
    tmp="$(mktemp -d)"
    rm -rf "$dest"
    mkdir -p "$dest"
    local url sha512
    for url in "$MIPS_BINUTILS_URL" "$MIPS_SFRAME_URL"; do
        if [[ "$url" == "$MIPS_BINUTILS_URL" ]]; then sha512="$MIPS_BINUTILS_SHA512"; else sha512="$MIPS_SFRAME_SHA512"; fi
        curl -fsSL -o "$tmp/pkg.deb" "$url"
        if ! echo "$sha512  $tmp/pkg.deb" | sha512sum -c --quiet -; then
            log_error "MIPS binutils: $(basename "$url") does not match the checksum recorded in this script; nothing installed."
            rm -rf "$tmp" "$dest"
            exit 1
        fi
        dpkg-deb -x "$tmp/pkg.deb" "$dest"
    done
    rm -rf "$tmp"
    "$dest/usr/bin/mips-linux-gnu-ld.bfd" --version >/dev/null 2>&1 \
        || { log_error "The MIPS binutils unpacked but do not run on this machine."; exit 1; }
    log_info "MIPS binutils installed in $dest."
}

setup_user_and_dirs() {
    log_step "The build user and its directories"
    getent passwd "$BUILD_USER" >/dev/null || useradd --system --home-dir "$STATE" \
        --shell /usr/sbin/nologin --user-group "$BUILD_USER"
    install -d -o "$BUILD_USER" -g "$BUILD_USER" -m 0711 "$STATE"
    install -d -o "$BUILD_USER" -g "$BUILD_USER" -m 0700 "$STATE/roms" "$STATE/src" "$STATE/work" "$STATE/home" "$STATE/overlays"
    # The only directory anything else may read; nginx serves it.
    install -d -o "$BUILD_USER" -g "$BUILD_USER" -m 0755 "$STATE/public"
}

install_tool() {
    log_step "Installing reon-patch-build"
    local src="$REON_SRC/maint/rom-patches"
    [[ -f "$src/reon-patch-build" ]] || { log_error "$src/reon-patch-build not found."; exit 1; }
    install -d -o root -g root -m 0755 "$TOOL_DIR"
    install -o root -g root -m 0755 "$src/reon-patch-build" "$TOOL_DIR/reon-patch-build"
    install -o root -g root -m 0644 "$src/bps.py" "$TOOL_DIR/bps.py"
    install -o root -g root -m 0644 "$src/games.json" "$TOOL_DIR/games.json"
    ln -sfn "$TOOL_DIR/reon-patch-build" /usr/local/bin/reon-patch-build
}

install_units() {
    log_step "Installing the service and the daily timer"
    cat > /etc/systemd/system/reon-patch-build.service <<EOF
[Unit]
Description=REON cron job: Build the game patches from their repositories
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=$BUILD_USER
Group=$BUILD_USER
Environment=REON_PATCH_STATE=$STATE
Environment=REON_TOOLCHAIN=$TOOLCHAIN
ExecStart=$TOOL_DIR/reon-patch-build
TimeoutStartSec=2h
# Keep a build from competing with the game for the machine (1 GB, 2 cores).
Nice=15
IOSchedulingClass=idle
MemoryMax=600M
# It runs code from the games' repositories (their Makefiles), so it gets
# nothing but its own directory and the network to fetch them.
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths=$STATE
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
RestrictSUIDSGID=yes
LockPersonality=yes
EOF
    cat > /etc/systemd/system/reon-patch-build.timer <<'EOF'
[Unit]
Description=Timer for reon-patch-build.service

[Timer]
OnCalendar=*-*-* 05:10:00
Persistent=true

[Install]
WantedBy=timers.target
EOF
    systemctl daemon-reload
    systemctl enable reon-patch-build.timer >/dev/null
    systemctl start reon-patch-build.timer
}

check_nginx() {
    if grep -rqs 'location /patches/' /etc/nginx/sites-enabled/ /etc/nginx/conf.d/ 2>/dev/null; then
        log_info "nginx already serves /patches/."
    else
        log_warn "nginx has no /patches/ location yet: re-run 1-setup-reon.sh (it writes it), then 4-harden-bots.sh."
    fi
}

print_summary() {
    log_step "Summary"
    log_info "timer: $(systemctl is-active reon-patch-build.timer 2>/dev/null || true)"
    runuser -u "$BUILD_USER" -- env REON_PATCH_STATE="$STATE" REON_TOOLCHAIN="$TOOLCHAIN" \
        "$TOOL_DIR/reon-patch-build" status || true
    log_info "Missing ROMs: sudo -u $BUILD_USER reon-patch-build add-rom <file.zip> ..., then: sudo systemctl start reon-patch-build.service"
}

main() {
    install_packages
    build_rgbds 0.6.1 "$RGBDS_0_6_1_URL" "$RGBDS_0_6_1_SHA256"
    build_rgbds 1.0.3 "$RGBDS_1_0_3_URL" "$RGBDS_1_0_3_SHA256"
    install_mips_binutils
    setup_user_and_dirs
    install_tool
    install_units
    check_nginx
    print_summary
}

main "$@"
