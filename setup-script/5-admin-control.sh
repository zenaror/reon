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

if [ "$(id -u)" -ne 0 ]; then
	echo "Run this as root (sudo $0)." >&2
	exit 1
fi

# Who actually serves the PHP, rather than who usually does.
#
# This used to default to www-data, and on the REON server that was wrong: it
# runs two php-fpm pools and nginx is pointed at the one owned by `reon`. The
# rule went to a user that never handles a request, so every button on the
# admin panel's Services page failed with a sudo refusal -- and because
# nothing checks a sudoers entry until something needs it, that stayed
# invisible for as long as nobody pressed one.
#
# So it is read off the configuration instead of assumed: find the socket
# nginx sends FastCGI to, then the pool that listens on it, then that pool's
# user. WEB_USER=... still overrides, and www-data is only the last resort.
detect_web_user() {
	local socket pool

	socket=$(grep -rhoE 'fastcgi_pass[[:space:]]+unix:[^;]+' \
		/etc/nginx/sites-enabled/ /etc/nginx/conf.d/ 2>/dev/null |
		head -1 | sed -E 's/.*unix:[[:space:]]*//')
	[ -n "$socket" ] || return 1

	# `listen` may be quoted or spaced differently, so match the path itself.
	pool=$(grep -rlF "$socket" /etc/php/*/fpm/pool.d/*.conf 2>/dev/null | head -1)
	[ -n "$pool" ] || return 1

	grep -hoE '^[[:space:]]*user[[:space:]]*=[[:space:]]*[^[:space:];]+' "$pool" |
		head -1 | sed -E 's/.*=[[:space:]]*//'
}

if [ -n "${WEB_USER:-}" ]; then
	echo "Using WEB_USER=$WEB_USER (given on the command line)."
else
	WEB_USER=$(detect_web_user || true)
	if [ -n "$WEB_USER" ]; then
		echo "Detected the web user as \"$WEB_USER\" from the nginx and PHP-FPM configuration."
	else
		WEB_USER=www-data
		echo "Could not tell which user serves the PHP; falling back to \"$WEB_USER\"." >&2
		echo "If the Services page reports that control is not enabled, re-run with WEB_USER=<user>." >&2
	fi
fi

if ! id -u "$WEB_USER" >/dev/null 2>&1; then
	echo "There is no user called \"$WEB_USER\"; nothing was granted." >&2
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
	reon-auto-schedule-refresh
	reon-mail-bottle
	reon-mail-trash-purge
	reon-service-status
	nginx
	postfix
)

# Units whose schedule and enabled state this script may touch. Only the
# timer-driven jobs -- there is no timer to reschedule on a daemon, and
# listing one here would be a way to ask for a drop-in on it. The on-demand
# refresh unit is absent for the same reason from the other side: it has no
# timer, and offering a schedule for something that must only ever be run by
# hand is how it ends up running by itself.
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

# The ban list (admin panel -> Banned IPs) gets its own helper and its own
# sudoers entry instead of more verbs on the one above: this one can only ever
# talk to fail2ban, so widening either helper never widens the other.
BAN_HELPER=/usr/local/sbin/reon-ban-ctl
BAN_SUDOERS=/etc/sudoers.d/reon-ban

cat > "$BAN_HELPER" <<'BANHELPEREOF'
#!/usr/bin/env bash
# Lists, adds and removes fail2ban bans for the admin panel. Called through
# exactly one sudoers entry, separate from reon-admin-ctl's: this one can only
# ever talk to fail2ban, and only in the three ways below.
#
#   reon-ban-ctl list             every current ban, as JSON
#   reon-ban-ctl ban <ip>         ban in the manual jail (web + mail ports)
#   reon-ban-ctl unban <ip>       lift the ban from every jail
#
# The address is never trusted: it is parsed as an IP before anything else
# happens, and a ban is refused unless it is a public address -- so the panel
# cannot be used to cut off localhost, the private network or a reserved
# range. The jail for a manual ban is fixed here, not taken from the caller.
set -euo pipefail

MANUAL_JAIL=reon-manual
verb="${1:-}"
ip="${2:-}"

# exit 0 public address, 1 not an address, 3 an address that is not public
check_ip() {
	python3 - "$1" <<'PY'
import ipaddress, sys
try:
    a = ipaddress.ip_address(sys.argv[1])
except ValueError:
    sys.exit(1)
sys.exit(0 if a.is_global else 3)
PY
}

case "$verb" in
	list)
		python3 <<'PY'
import json, re, sqlite3, subprocess

def run(*cmd):
    return subprocess.run(cmd, capture_output=True, text=True).stdout

status = run("fail2ban-client", "status")
jails = []
for line in status.splitlines():
    if "Jail list" in line:
        jails = [j.strip() for j in line.split(":", 1)[1].split(",") if j.strip()]

db = sqlite3.connect("file:/var/lib/fail2ban/fail2ban.sqlite3?mode=ro", uri=True)
out = []
for jail in jails:
    for line in run("fail2ban-client", "get", jail, "banip", "--with-time").splitlines():
        m = re.match(r"^(\S+)\s+(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d) \+ (-?\d+) = (\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)", line)
        if not m:
            continue
        ip, since, seconds, until = m.groups()
        row = db.execute(
            "select bancount, data from bans where jail=? and ip=? order by timeofban desc limit 1",
            (jail, ip)).fetchone()
        matches = []
        count = 1
        if row:
            count = row[0]
            try:
                matches = json.loads(row[1]).get("matches", [])
            except Exception:
                matches = []
        out.append({
            "jail": jail, "ip": ip, "since": since,
            "until": None if int(seconds) < 0 else until,
            "count": count,
            "matches": [str(x)[:200] for x in matches[:3]],
        })
print(json.dumps(out))
PY
		;;
	ban)
		rc=0; check_ip "$ip" || rc=$?
		if [ "$rc" -eq 1 ]; then echo "refused: $ip is not an IP address" >&2; exit 64; fi
		if [ "$rc" -ne 0 ]; then echo "refused: $ip is not a public address" >&2; exit 2; fi
		if ! fail2ban-client status "$MANUAL_JAIL" >/dev/null 2>&1; then
			echo "refused: the $MANUAL_JAIL jail is not running (install it with 3-harden-server.sh)" >&2
			exit 4
		fi
		fail2ban-client set "$MANUAL_JAIL" banip "$ip"
		;;
	unban)
		rc=0; check_ip "$ip" || rc=$?
		if [ "$rc" -eq 1 ]; then echo "refused: $ip is not an IP address" >&2; exit 64; fi
		# A non-public address cannot have been banned through the panel, but
		# lifting a ban is always harmless, so it is allowed.
		fail2ban-client unban "$ip"
		;;
	*)
		echo "usage: reon-ban-ctl list | ban <ip> | unban <ip>" >&2
		exit 64
		;;
esac
BANHELPEREOF

chown root:root "$BAN_HELPER"
chmod 0755 "$BAN_HELPER"

cat > "$SUDOERS" <<SUDOEOF
# The admin panel's service controls. One command, no password, nothing else.
$WEB_USER ALL=(root) NOPASSWD: $HELPER
SUDOEOF

cat > "$BAN_SUDOERS" <<SUDOEOF
# The admin panel's ban list. One command, no password, nothing else.
$WEB_USER ALL=(root) NOPASSWD: $BAN_HELPER
SUDOEOF

chown root:root "$SUDOERS" "$BAN_SUDOERS"
chmod 0440 "$SUDOERS" "$BAN_SUDOERS"

# A malformed sudoers file locks the machine's sudo entirely, so it is
# checked before it is left in place.
if ! visudo -c -f "$SUDOERS" || ! visudo -c -f "$BAN_SUDOERS"; then
	rm -f "$SUDOERS" "$BAN_SUDOERS"
	echo "sudoers entry was rejected and has been removed; nothing was granted." >&2
	exit 1
fi

echo "Installed $HELPER and granted $WEB_USER the right to run it."
echo "The Services and Logs pages of the admin panel will now work."
