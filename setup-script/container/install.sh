#!/usr/bin/env bash
set -Eeuo pipefail
# Sources come from the image. Existing generated data is retained when code
# is overlaid; never import accounts, ROMs or private configuration from build.
for name in reon mobile-relay; do
    mkdir -p "/srv/reon-install/$name"
    if [[ "$name" == reon && -d /srv/reon-install/reon/web ]]; then
        # Preserve operator-managed rotation state and feature configuration.
        tar -C /usr/share/reon-source/reon \
            --exclude=./app/auto-schedule/files \
            --exclude=./web/cgb/pokemon/bxt_config.php \
            --exclude=./web/cgb/pokemon/bxt_runtime_state.json -cf - . |
            tar -C /srv/reon-install/reon -xf -
    else
        cp -a "/usr/share/reon-source/$name/." "/srv/reon-install/$name/"
    fi
done
for item in 'reon/config.json' 'reon/.env' 'mobile-relay/config.ini'; do
    if [[ ! -f "/srv/reon-install/$item" ]]; then
        [[ -f "/run/reon-secrets/$item" ]] || { echo "Missing runtime configuration: $item" >&2; exit 1; }
        install -m 0600 "/run/reon-secrets/$item" "/srv/reon-install/$item"
    fi
done
export REON_CONTAINER=1 SHORTCUT_USER=root
export COMPOSER_HOME=/var/cache/reon-composer DOTNET_CLI_HOME=/var/lib/reon-dotnet
mkdir -p "$COMPOSER_HOME" "$DOTNET_CLI_HOME"
cd /srv/reon-install/reon
bash setup-script/1-setup-reon.sh
bash setup-script/2-setup-postfix-bridge.sh
bash setup-script/3-harden-server.sh
bash setup-script/4-harden-bots.sh
bash setup-script/5-admin-control.sh
[[ "${INSTALL_PATCHES:-1}" != 1 ]] || bash setup-script/6-setup-rom-patches.sh
mkdir -p /var/lib/reon-container
cp /usr/share/reon-source/revision /var/lib/reon-container/revision
printf 'Ready: REON installation completed.\n'
