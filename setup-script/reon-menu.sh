#!/usr/bin/env bash
# A terminal menu for running the REON server: status, logs, restarts, jobs,
# bans, backups, resources. Everything it does is a plain systemctl /
# journalctl / fail2ban call you could type yourself; the menu only saves you
# remembering the names. Installed as `reon-menu` by 1-setup-reon.sh.
#
#   reon-menu
set -u

SVC=(reon-mail reon-mobile-relay reon-relay-policy nginx php8.5-fpm postfix dovecot mysql dnsmasq fail2ban)
JOBS=(reon-pokemon-battle reon-pokemon-exchange reon-auto-schedule reon-mail-bottle reon-mail-trash-purge reon-retention-purge reon-service-status reon-db-backup)

# Only ask for sudo when we are not root already.
if [ "$(id -u)" -eq 0 ]; then S=""; else S="sudo"; fi

bold() { printf '\n\033[1m== %s ==\033[0m\n' "$*"; }
pause() { printf '\n[Enter to go back] '; read -r _; }

# Shows a numbered list of the arguments, returns the chosen one in $CHOICE.
pick() {
	local title="$1"; shift
	local i=1 n
	bold "$title"
	for n in "$@"; do printf '  %2d) %s\n' "$i" "$n"; i=$((i + 1)); done
	printf '   0) back\n> '
	read -r n
	CHOICE=""
	case "$n" in ''|*[!0-9]*|0) return 1 ;; esac
	[ "$n" -le "$#" ] || return 1
	CHOICE="${!n}"
}

status() {
	bold "Services"
	for u in "${SVC[@]}"; do
		printf '  %-22s %s\n' "$u" "$(systemctl is-active "$u" 2>/dev/null)"
	done
	bold "Timers"
	systemctl list-timers 'reon-*' --no-pager 2>/dev/null | sed -n '1,14p'
	bold "Failed units"
	systemctl --failed --no-legend --no-pager | sed 's/^/  /'
	[ -z "$(systemctl --failed --no-legend)" ] && echo "  none"
}

follow_logs() {
	pick "Follow which log? (Ctrl+C to stop)" \
		"everything (journal)" "mail" "mobile relay" "web (nginx + php-fpm)" "jobs (cron)" \
		"Activity: sign-ups, logins, downloads, trades (file)" "PHP site log (file)" "nginx access/error (files)" "fail2ban (file)" || return
	case "$CHOICE" in
		everything*) $S journalctl -f -u 'reon-*' -u nginx -u postfix -u dovecot -u mysql -u dnsmasq ;;
		mail)        $S journalctl -f -u reon-mail -u reon-relay-policy -u postfix -u dovecot ;;
		"mobile relay") $S journalctl -f -u reon-mobile-relay ;;
		web*)        $S journalctl -f -u nginx -u php8.5-fpm ;;
		jobs*)       $S journalctl -f -u reon-pokemon-battle -u reon-pokemon-exchange -u reon-auto-schedule -u reon-mail-bottle ;;
		Activity*)   $S tail -n 40 -F /var/log/reon/activity.log ;;
		PHP*)        $S tail -n 40 -F /var/log/reon/php-error.log ;;
		nginx*)      $S tail -n 30 -F /var/log/nginx/reon.access.log /var/log/nginx/reon.error.log ;;
		fail2ban*)   $S tail -n 40 -F /var/log/fail2ban.log ;;
	esac
}

problems() {
	bold "Warnings and errors, last 24 h (services)"
	$S journalctl --since -24h -p warning --no-pager -q -u 'reon-*' -u nginx -u postfix -u dovecot -u mysql | tail -n 60
	bold "PHP site log: error and warn lines (last 30)"
	$S grep -E '"level":"(error|warn)"|PHP (Fatal|Warning|Parse)' /var/log/reon/php-error.log 2>/dev/null | tail -n 30
}

restart_service() {
	pick "Restart which service?" "${SVC[@]}" || return
	printf 'Restart %s? [y/N] ' "$CHOICE"; read -r a
	[ "$a" = y ] || return
	$S systemctl restart "$CHOICE" && echo "restarted." ; systemctl is-active "$CHOICE"
}

run_job() {
	pick "Run which job now?" "${JOBS[@]}" || return
	$S systemctl start "$CHOICE.service" && echo "started (see: journalctl -u $CHOICE -n 20)"
	$S journalctl -u "$CHOICE.service" -n 15 --no-pager -q
}

bans() {
	bold "Banned addresses"
	if [ -x /usr/local/sbin/reon-ban-ctl ]; then
		$S /usr/local/sbin/reon-ban-ctl list | head -c 4000; echo
	else
		$S fail2ban-client banned
	fi
	printf '\nTo ban or unban, use the admin panel (/admin/bans.php): it requires a reason and keeps the record.\n'
}

backups() {
	bold "Backups (/var/backups/reon, kept 7 days)"
	$S ls -lh --time-style=long-iso /var/backups/reon | tail -n 25
	printf '\nRun a backup now? [y/N] '; read -r a
	[ "$a" = y ] && $S systemctl start reon-db-backup.service && echo done
}

resources() {
	bold "Load and memory"
	uptime; free -m
	bold "Disk"
	df -h / | sed 's/^/  /'
	bold "Top 8 by memory"
	ps -eo pmem,rss,comm --sort=-rss | head -n 9
}

while true; do
	bold "REON server menu"
	cat <<'MENU'
   1) Status: services, timers, failed units
   2) Follow a log
   3) Recent warnings and errors
   4) Restart a service
   5) Run a job now
   6) Banned addresses
   7) Backups
   8) Load, memory, disk
   9) Database shell (mysql)
   0) Quit
MENU
	printf '> '; read -r c
	case "$c" in
		1) status; pause ;;
		2) follow_logs ;;
		3) problems; pause ;;
		4) restart_service; pause ;;
		5) run_job; pause ;;
		6) bans; pause ;;
		7) backups; pause ;;
		8) resources; pause ;;
		9) $S mysql reon_db ;;
		0|q) exit 0 ;;
	esac
done
