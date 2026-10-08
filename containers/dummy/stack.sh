#!/usr/bin/env bash
set -euo pipefail
dummy_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
dummy_env="$dummy_dir/.env"
dummy_action="${1:-status}"
shift || true
compose_files=(-f "$dummy_dir/compose.yml")
if [[ -f "$dummy_dir/compose.local.yml" ]]; then compose_files+=(-f "$dummy_dir/compose.local.yml"); fi
if [[ "${DUMMY_DEVELOPMENT:-0}" == 1 ]]; then compose_files+=(-f "$dummy_dir/compose.development.yml"); fi
case "$dummy_action" in
  init)
    mkdir -p "$dummy_dir/content"
    umask 077
    python3 - "$dummy_env" <<'PYINIT'
import secrets,sys
from pathlib import Path
p=Path(sys.argv[1]);text=p.read_text() if p.exists() else ''
for k in ('DUMMY_INTERNAL_TOKEN','DUMMY_DB_PASSWORD','DUMMY_DB_ROOT_PASSWORD'):
    if not any(line.startswith(k+'=') for line in text.splitlines()):
        text+=k+'='+secrets.token_hex(32)+'\n'
p.write_text(text)
PYINIT
    echo 'Dummy server initialized; existing configuration preserved.'
    ;;
  up)
    [[ -f "$dummy_env" ]] || { echo 'Run bash containers/dummy/stack.sh init first.' >&2; exit 1; }
    podman build --skip-unused-stages=true --format docker --target dummy-server -t localhost/reon-dummy-server:local "$dummy_dir/../.."
    podman build --skip-unused-stages=true --format docker --target dummy-web -t localhost/reon-dummy-web:local "$dummy_dir/../.."
    podman compose --env-file "$dummy_env" -p reon-dummy "${compose_files[@]}" up -d --no-build "$@"
    ;;
  standard-ports)
    dummy_min_port="$(cat /proc/sys/net/ipv4/ip_unprivileged_port_start)"
    if (( dummy_min_port > 25 )); then
      echo 'Host blocks low ports. Run: sudo sysctl -w net.ipv4.ip_unprivileged_port_start=25' >&2
      exit 1
    fi
    [[ -f "$dummy_env" ]] || { echo 'Run stack.sh init first.' >&2; exit 1; }
    python3 - "$dummy_env" <<'PYPORT'
from pathlib import Path
import sys
p=Path(sys.argv[1]);ports={'DUMMY_HTTP_PORT':'80','DUMMY_SMTP_PORT':'25','DUMMY_POP3_PORT':'110','DUMMY_SUBMISSION_PORT':'587','DUMMY_DNS_PORT':'53'}
lines=[line for line in p.read_text().splitlines() if not any(line.startswith(k+'=') for k in ports)]
p.write_text('\n'.join(lines)+'\n'+''.join(k+'='+v+'\n' for k,v in ports.items()))
PYPORT
    podman compose --env-file "$dummy_env" -p reon-dummy "${compose_files[@]}" up -d --no-build --remove-orphans
    ;;
  down|status|logs)
    [[ -f "$dummy_env" ]] || { echo 'Dummy server has not been initialized.' >&2; exit 1; }
    if [[ "$dummy_action" == status ]]; then dummy_action=ps; fi
    podman compose --env-file "$dummy_env" -p reon-dummy "${compose_files[@]}" "$dummy_action" "$@"
    ;;
  *) echo 'Usage: stack.sh init|up|standard-ports|down|status|logs [compose options]' >&2; exit 2 ;;
esac
