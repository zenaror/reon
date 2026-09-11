#!/usr/bin/env bash
# Installs the helper the admin panel uses to restart a service and read its
# journal, plus the single sudoers entry that lets the web server call it.
#
# Run this only if you want those buttons to work. Without it the panel says
# so and does nothing, which is a perfectly good state to leave it in.
#
#   sudo ./5-admin-control.sh
#
# What this grants: the user PHP runs as may execute ONE script as root, and
# that script accepts a fixed set of verbs and a unit name from a list it
# holds itself. It cannot be asked to touch a unit that is not on that list.
# The list lives here, on the server, rather than in a form field -- so
# widening it is a deliberate act by whoever administers the machine, not
# something the web application can do.
#
# Changing a job's schedule is written as a systemd drop-in under
# /etc/systemd/system/<unit>.timer.d/, never into the unit file. On this
# server those unit files are symlinks into the checkout, so editing one
# would be editing the repository; a drop-in leaves the shipped unit exactly
# as it came and is undone by deleting one file.
set -euo pipefail

HELPER=/usr/local/sbin/reon-admin-ctl
SUDOERS=/etc/sudoers.d/reon-admin
WEB_USER="${WEB_USER:-www-data}"

if [ "$(id -u)" -ne 0 ]; then
	echo "Run this as root (sudo $0)." >&2
	exit 1
fi

cat > "$HELPER" <<'HELPEREOF'
#!/usr/bin/env bash
# Starts, stops, restarts and reschedules a REON unit, or prints the tail of
# its journal. Called by the admin panel through exactly one sudoers entry.
#
# The unit name is never trusted: it is matched against the list below and
# anything else is refused before systemctl is reached.
set -euo pipefail

ALLOWED=(
	reon-mail
	reon-mobile-relay
	reon-relay-policy
	reon-pokemon-exchange
	reon-pokemon-battle
	reon-auto-schedule
	reon-mail-bottle
	reon-mail-trash-purge
	reon-service-status
	nginx
	postfix
)

# Units whose schedule and enabled state this script may touch. Only the
# timer-driven jobs -- there is no timer to reschedule on a daemon, and
# listing one here would be a way to ask for a drop-in on it.
TIMED=(
	reon-pokemon-exchange
	reon-pokemon-battle
	reon-auto-schedule
	reon-mail-bottle
	reon-mail-trash-purge
	reon-service-status
)

# Stopping nginx from a page nginx is serving is a one-way door: the panel
# that would start it again goes down with it. Restart is fine -- it comes
# back on its own.
NEVER_STOP=(
	nginx
)

verb="${1:-}"
unit="${2:-}"
arg="${3:-}"

in_list() {
	local needle="$1"; shift
	local item
	for item in "$@"; do
		if [ "$item" = "$needle" ]; then return 0; fi
	done
	return 1
}

if ! in_list "$unit" "${ALLOWED[@]}"; then
	echo "refused: $unit is not on the list" >&2
	exit 2
fi

require_timed() {
	if ! in_list "$unit" "${TIMED[@]}"; then
		echo "refused: $unit has no timer" >&2
		exit 2
	fi
}

case "$verb" in
	start|restart)
		systemctl "$verb" "$unit.service"
		systemctl is-active "$unit.service" || true
		;;
	stop)
		if in_list "$unit" "${NEVER_STOP[@]}"; then
			echo "refused: stopping $unit would take down the panel itself" >&2
			exit 2
		fi
		systemctl stop "$unit.service"
		systemctl is-active "$unit.service" || true
		;;
	run)
		# A one-shot job: start it now, out of its schedule.
		require_timed
		systemctl start "$unit.service"
		echo started
		;;
	enable|disable)
		require_timed
		systemctl "$verb" --now "$unit.timer"
		systemctl is-enabled "$unit.timer" || true
		;;
	timer-show)
		require_timed
		echo "enabled=$(systemctl is-enabled "$unit.timer" 2>/dev/null || echo unknown)"
		echo "active=$(systemctl is-active "$unit.timer" 2>/dev/null || echo unknown)"
		# TimersCalendar reads "{ OnCalendar=... ; next_elapse=... }"; the
		# schedule is the part the panel shows and lets you edit.
		calendar="$(systemctl show "$unit.timer" -p TimersCalendar --value 2>/dev/null || true)"
		schedule="$(printf '%s' "$calendar" | sed -n 's/.*OnCalendar=\(.*\) ; next_elapse=.*/\1/p')"
		echo "schedule=$schedule"
		echo "next=$(systemctl show "$unit.timer" -p NextElapseUSecRealtime --value 2>/dev/null || true)"
		if [ -f "/etc/systemd/system/$unit.timer.d/override.conf" ]; then
			echo "override=yes"
		else
			echo "override=no"
		fi
		;;
	timer-set)
		require_timed
		if [ -z "$arg" ]; then
			echo "refused: empty schedule" >&2
			exit 64
		fi
		# systemd itself decides whether the expression is valid, before
		# anything is written. A drop-in with a bad OnCalendar would leave
		# the job silently never running again.
		if ! systemd-analyze calendar "$arg" >/dev/null 2>&1; then
			echo "refused: $arg is not a valid systemd calendar expression" >&2
			exit 65
		fi
		dir="/etc/systemd/system/$unit.timer.d"
		mkdir -p "$dir"
		# The bare OnCalendar= empties the inherited list first, which is the
		# systemd idiom for replacing rather than adding to it.
		cat > "$dir/override.conf" <<DROPIN
# Written by the REON admin panel. Delete this file to go back to the
# schedule the unit ships with.
[Timer]
OnCalendar=
OnCalendar=$arg
DROPIN
		systemctl daemon-reload
		if [ "$(systemctl is-enabled "$unit.timer" 2>/dev/null || true)" = "enabled" ]; then
			systemctl restart "$unit.timer"
		fi
		echo "$arg"
		;;
	timer-reset)
		require_timed
		rm -f "/etc/systemd/system/$unit.timer.d/override.conf"
		rmdir "/etc/systemd/system/$unit.timer.d" 2>/dev/null || true
		systemctl daemon-reload
		if [ "$(systemctl is-enabled "$unit.timer" 2>/dev/null || true)" = "enabled" ]; then
			systemctl restart "$unit.timer"
		fi
		echo reset
		;;
	log)
		case "$arg" in
			''|*[!0-9]*) arg=200 ;;
		esac
		if [ "$arg" -gt 1000 ]; then arg=1000; fi
		journalctl -u "$unit.service" -n "$arg" --no-pager --output short-iso
		;;
	*)
		echo "usage: reon-admin-ctl start|stop|restart|run|enable|disable|timer-show|timer-set|timer-reset|log <unit> [arg]" >&2
		exit 64
		;;
esac
HELPEREOF

chown root:root "$HELPER"
chmod 0755 "$HELPER"

cat > "$SUDOERS" <<SUDOEOF
# The admin panel's service controls. One command, no password, nothing else.
$WEB_USER ALL=(root) NOPASSWD: $HELPER
SUDOEOF

chown root:root "$SUDOERS"
chmod 0440 "$SUDOERS"

# A malformed sudoers file locks the machine's sudo entirely, so it is
# checked before it is left in place.
if ! visudo -c -f "$SUDOERS"; then
	rm -f "$SUDOERS"
	echo "sudoers entry was rejected and has been removed; nothing was granted." >&2
	exit 1
fi

echo "Installed $HELPER and granted $WEB_USER the right to run it."
echo "The Services and Logs pages of the admin panel will now work."
