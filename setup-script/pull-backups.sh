#!/usr/bin/env bash
# Copies the server's nightly database backups to THIS machine (run it from
# your own computer, not on the server). The backups on the server live on the
# same disk as the database, so they do not survive losing the machine; this is
# the cheapest way to have a copy somewhere else.
#
#   setup-script/pull-backups.sh <user@host> [ssh key] [destination folder]
#
# defaults: key = your normal ssh identity, destination = ./reon-backups
#
# /var/backups/reon is root-only on the server, so the files are read through
# `sudo tar` over ssh (one connection). Only files you do not already have are
# copied, and nothing is ever deleted locally: prune the destination yourself.
# The files hold every password hash and game login password -- keep the
# destination somewhere private.
set -euo pipefail

host="${1:?usage: pull-backups.sh <user@host> [ssh key] [destination folder]}"
key="${2:-}"
dest="${3:-./reon-backups}"

ssh_opts=(-o IdentitiesOnly=yes)
if [ -n "$key" ]; then ssh_opts+=(-i "$key"); fi

mkdir -p "$dest"
chmod 700 "$dest"

# Names of the dumps only (the folder can also hold older manual copies).
remote_list="$(ssh "${ssh_opts[@]}" "$host" "sudo -n find /var/backups/reon -maxdepth 1 -type f \( -name 'mysql-*.sql.gz' -o -name 'relay-*.db.gz' \) -printf '%f\n'" | sort)"

wanted=()
while IFS= read -r name; do
	[ -n "$name" ] || continue
	[ -e "$dest/$name" ] || wanted+=("$name")
done <<< "$remote_list"

if [ "${#wanted[@]}" -eq 0 ]; then
	echo "Nothing new: $dest already has every backup the server lists."
	exit 0
fi

umask 077
printf '%s\n' "${wanted[@]}" |
	ssh "${ssh_opts[@]}" "$host" 'sudo -n tar cf - -C /var/backups/reon -T -' |
	tar xf - -C "$dest"

echo "Copied ${#wanted[@]} file(s) to $dest:"
printf '  %s\n' "${wanted[@]}"
